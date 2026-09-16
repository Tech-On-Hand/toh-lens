use crate::db::{config_repo, session_repo};
use crate::models::SyncResult;
use crate::state::AppState;

const SYNC_BATCH_LIMIT: i64 = 200;

/// The single entry point for every sync trigger (the periodic background
/// loop, the post-logout best-effort push, and the manual "Sync Now"
/// command) so they all behave identically.
///
/// Never holds the DB lock across an `.await` — it's acquired briefly to
/// read, dropped for the HTTP round-trip, then re-acquired briefly to write.
pub async fn try_sync(state: &AppState) -> SyncResult {
    let (base_url, token) = {
        let conn = state.db.lock().unwrap();
        let base_url = config_repo::get_api_base_url(&conn).ok().flatten();
        let token = config_repo::get_api_token(&conn).ok().flatten();
        (base_url, token)
    };

    let (Some(base_url), Some(token)) = (base_url, token) else {
        return SyncResult::default();
    };

    if !crate::api_client::ping(&state.http, &base_url).await {
        return SyncResult::default();
    }

    {
        let conn = state.db.lock().unwrap();
        let _ = config_repo::set_last_sync_attempt_at(&conn, &chrono::Utc::now().to_rfc3339());
    }

    let batch = {
        let conn = state.db.lock().unwrap();
        session_repo::unsynced_batch(&conn, SYNC_BATCH_LIMIT).unwrap_or_default()
    };

    if batch.is_empty() {
        return SyncResult::default();
    }

    let attempted = batch.len();

    match crate::api_client::post_sessions_sync(&state.http, &base_url, &token, batch).await {
        Ok(response) => {
            let synced_uuids: Vec<String> = response
                .results
                .iter()
                .filter(|r| r.status == "synced")
                .map(|r| r.uuid.clone())
                .collect();

            let synced = synced_uuids.len();

            let conn = state.db.lock().unwrap();
            let _ = session_repo::mark_synced(&conn, &synced_uuids);
            if synced > 0 {
                let _ = config_repo::set_last_sync_success_at(&conn, &chrono::Utc::now().to_rfc3339());
            }

            SyncResult {
                attempted,
                synced,
                failed: attempted - synced,
            }
        }
        Err(_) => SyncResult {
            attempted,
            synced: 0,
            failed: attempted,
        },
    }
}
