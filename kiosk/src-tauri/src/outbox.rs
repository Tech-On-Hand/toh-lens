//! Delivers what a student did while the server could not be reached (see
//! `db/outbox_repo.rs`). Called every tick and right after something is queued.
//! Delivery stops at the first entry that cannot be sent yet, so order is kept,
//! and an entry the server will never accept is dropped rather than retried forever.

use crate::db::{config_repo, outbox_repo, outbox_repo::Entry};
use crate::state::AppState;
use chrono::{DateTime, Duration, Utc};
use reqwest::{Method, StatusCode};
use serde_json::{json, Value};
use std::sync::Arc;
use tokio::sync::Mutex;

/// A raised hand that has sat unsent this long is no longer a live request for
/// help, so it is dropped instead of surprising the teacher much later.
const HELP_REQUEST_LIFETIME: Duration = Duration::minutes(10);
const BATCH: i64 = 50;

#[derive(Debug, PartialEq)]
pub enum Delivery {
    Done,
    /// Offline, or the server is having a moment: keep it and try again later.
    Retry,
    /// The server refused it for a reason that will not change: give up on it.
    Drop,
}

/// What to do with a response: success is done, "not there yet" and server-side
/// trouble are worth retrying, anything else is a refusal that will not improve.
pub fn classify(status: StatusCode, error_code: Option<&str>) -> Delivery {
    if status.is_success() {
        return Delivery::Done;
    }
    match status {
        // The kiosk syncs login sessions separately, so the session a message
        // belongs to may simply not have reached the server yet.
        StatusCode::CONFLICT if error_code == Some("SESSION_UNKNOWN") => Delivery::Retry,
        StatusCode::REQUEST_TIMEOUT | StatusCode::TOO_MANY_REQUESTS => Delivery::Retry,
        status if status.is_server_error() => Delivery::Retry,
        _ => Delivery::Drop,
    }
}

/// `None` means this entry is no longer worth sending at all.
fn request_for(entry: &Entry, now: DateTime<Utc>) -> Option<(Method, String, Option<Value>)> {
    let key = entry.key.as_deref().unwrap_or_default();
    match entry.kind.as_str() {
        outbox_repo::CHAT_MESSAGE => Some((Method::POST, "messages".into(), Some(entry.payload.clone()))),
        outbox_repo::HELP_REQUEST => {
            let queued_at = DateTime::parse_from_rfc3339(&entry.created_at).ok()?.with_timezone(&Utc);
            (now - queued_at <= HELP_REQUEST_LIFETIME).then(|| (Method::POST, "help-requests".to_string(), Some(entry.payload.clone())))
        }
        outbox_repo::HELP_CANCEL => Some((Method::POST, format!("help-requests/{key}/cancel"), None)),
        outbox_repo::ANNOUNCEMENT_READ => Some((Method::POST, format!("announcements/{key}/read"), None)),
        outbox_repo::CHAT_READ => Some((Method::POST, "messages/read".into(), None)),
        _ => None,
    }
}

async fn deliver(state: &AppState, base_url: &str, token: &str, method: Method, path: &str, body: Option<Value>) -> Delivery {
    let mut request = state
        .http
        .request(method, format!("{}/api/v1/device/{path}", base_url.trim_end_matches('/')))
        .bearer_auth(token);
    if let Some(body) = body {
        request = request.json(&body);
    }
    let Ok(response) = request.send().await else { return Delivery::Retry };

    let status = response.status();
    let code = if status == StatusCode::CONFLICT {
        response.json::<Value>().await.ok().and_then(|body| body["error"]["code"].as_str().map(str::to_owned))
    } else {
        None
    };
    classify(status, code.as_deref())
}

#[derive(Clone, Default)]
pub struct Outbox {
    running: Arc<Mutex<()>>,
}

impl Outbox {
    /// Sends what is waiting, oldest first. Safe to call from several places at
    /// once: if a flush is already running this one simply returns.
    pub async fn flush(&self, state: &AppState) {
        let Ok(_running) = self.running.try_lock() else { return };

        let (base_url, token, entries) = {
            let conn = state.db.lock().unwrap();
            (
                config_repo::get_api_base_url(&conn).ok().flatten(),
                crate::credential_store::get_token(),
                outbox_repo::pending(&conn, BATCH).unwrap_or_default(),
            )
        };
        let (Some(base_url), Some(token)) = (base_url, token) else { return };

        for entry in entries {
            let outcome = match request_for(&entry, Utc::now()) {
                None => Delivery::Drop,
                Some((method, path, body)) => deliver(state, &base_url, &token, method, &path, body).await,
            };

            match outcome {
                Delivery::Retry => return,
                Delivery::Done | Delivery::Drop => {
                    if outcome == Delivery::Drop {
                        log::warn!("outbox: dropping a {} entry the server will not accept", entry.kind);
                    }
                    let conn = state.db.lock().unwrap();
                    let _ = outbox_repo::delete(&conn, entry.id);
                }
            }
        }
    }
}

/// Queues something and starts delivering it in the background, so the student's
/// tap returns at once whether or not the server can be reached.
pub fn queue(state: &AppState, kind: &str, key: Option<&str>, payload: Value) -> Result<(), String> {
    {
        let conn = state.db.lock().unwrap();
        outbox_repo::enqueue(&conn, kind, key, &payload).map_err(|e| e.to_string())?;
    }
    let state = state.clone();
    tauri::async_runtime::spawn(async move { state.outbox.flush(&state).await });
    Ok(())
}

pub fn chat_payload(uuid: &str, body: &str, session_uuid: &str) -> Value {
    json!({ "uuid": uuid, "body": body, "session_uuid": session_uuid })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn success_is_done() {
        assert_eq!(classify(StatusCode::OK, None), Delivery::Done);
        assert_eq!(classify(StatusCode::CREATED, None), Delivery::Done);
    }

    #[test]
    fn a_session_the_server_has_not_seen_yet_is_retried() {
        assert_eq!(classify(StatusCode::CONFLICT, Some("SESSION_UNKNOWN")), Delivery::Retry);
    }

    #[test]
    fn other_conflicts_are_dropped() {
        assert_eq!(classify(StatusCode::CONFLICT, Some("SESSION_ENDED")), Delivery::Drop);
        assert_eq!(classify(StatusCode::CONFLICT, None), Delivery::Drop);
    }

    #[test]
    fn server_trouble_and_throttling_are_retried_but_refusals_are_not() {
        assert_eq!(classify(StatusCode::INTERNAL_SERVER_ERROR, None), Delivery::Retry);
        assert_eq!(classify(StatusCode::BAD_GATEWAY, None), Delivery::Retry);
        assert_eq!(classify(StatusCode::TOO_MANY_REQUESTS, None), Delivery::Retry);
        assert_eq!(classify(StatusCode::UNPROCESSABLE_ENTITY, None), Delivery::Drop);
        assert_eq!(classify(StatusCode::NOT_FOUND, None), Delivery::Drop);
        assert_eq!(classify(StatusCode::UNAUTHORIZED, None), Delivery::Drop);
    }

    fn entry(kind: &str, key: Option<&str>, created_at: &str) -> Entry {
        Entry { id: 1, kind: kind.into(), key: key.map(str::to_owned), payload: json!({}), created_at: created_at.into() }
    }

    #[test]
    fn a_stale_raised_hand_is_not_sent() {
        let now = Utc::now();
        let old = (now - Duration::minutes(11)).to_rfc3339();
        let fresh = (now - Duration::minutes(2)).to_rfc3339();

        assert!(request_for(&entry(outbox_repo::HELP_REQUEST, Some("r"), &old), now).is_none());
        assert!(request_for(&entry(outbox_repo::HELP_REQUEST, Some("r"), &fresh), now).is_some());
    }

    #[test]
    fn a_chat_message_never_goes_stale() {
        let now = Utc::now();
        let old = (now - Duration::days(2)).to_rfc3339();

        assert!(request_for(&entry(outbox_repo::CHAT_MESSAGE, Some("m"), &old), now).is_some());
    }

    #[test]
    fn each_kind_targets_its_own_endpoint() {
        let now = Utc::now();
        let created = now.to_rfc3339();
        let path = |kind: &str, key: &str| request_for(&entry(kind, Some(key), &created), now).unwrap().1;

        assert_eq!(path(outbox_repo::HELP_CANCEL, "abc"), "help-requests/abc/cancel");
        assert_eq!(path(outbox_repo::ANNOUNCEMENT_READ, "xyz"), "announcements/xyz/read");
        assert_eq!(path(outbox_repo::CHAT_READ, ""), "messages/read");
        assert_eq!(path(outbox_repo::CHAT_MESSAGE, "m"), "messages");
    }
}
