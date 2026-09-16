use crate::db::{config_repo, roster_repo, session_repo};
use crate::models::SyncResult;
use crate::state::AppState;
use chrono::Utc;

const SYNC_BATCH_LIMIT: i64 = 200;

/// SRS Risk #5: without this, a newly enrolled student never validates at
/// the kiosk until someone manually redoes Setup. Piggybacks on the same
/// periodic tick as session sync rather than running on its own schedule.
const ROSTER_REFRESH_INTERVAL_HOURS: i64 = 6;

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

    maybe_refresh_roster(state, &base_url, &token).await;

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

/// Re-fetches the roster if it hasn't refreshed recently, so newly enrolled
/// students eventually validate without staff needing to redo Setup.
/// Failures are silent and simply retried on the next tick.
async fn maybe_refresh_roster(state: &AppState, base_url: &str, token: &str) {
    let (school_id, last_synced_at) = {
        let conn = state.db.lock().unwrap();
        match config_repo::get_config(&conn) {
            Ok(Some(config)) => (config.school_id, config.last_roster_synced_at),
            _ => return,
        }
    };

    let is_stale = match last_synced_at
        .as_deref()
        .and_then(|s| chrono::DateTime::parse_from_rfc3339(s).ok())
    {
        Some(last) => Utc::now().signed_duration_since(last) > chrono::Duration::hours(ROSTER_REFRESH_INTERVAL_HOURS),
        None => true,
    };

    if !is_stale {
        return;
    }

    if let Ok(roster) = crate::api_client::fetch_roster(&state.http, base_url, token).await {
        let now = Utc::now().to_rfc3339();
        let mut conn = state.db.lock().unwrap();
        if roster_repo::replace_roster(&mut conn, school_id, &roster.students).is_ok() {
            let _ = config_repo::set_last_roster_synced_at(&conn, &now);
        }
    }
}
