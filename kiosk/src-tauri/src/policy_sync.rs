//! Keeps this computer's classroom policy (blocked sites and focus sessions) in
//! step with the backend and hands it to the browser extension, which does the
//! actual enforcing. The last policy is cached in SQLite so it survives restarts
//! and network outages.

use crate::db::policy_repo;
use crate::state::AppState;
use chrono::{DateTime, Duration, Utc};
use serde::{Deserialize, Serialize};
use serde_json::json;
use uuid::Uuid;

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct CachedFocus {
    pub id: String,
    pub name: Option<String>,
    pub allowed_domains: Vec<String>,
    /// The deadline on THIS computer's clock (see `to_local`).
    pub ends_at: String,
}

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct CachedPolicy {
    pub version: String,
    pub block: Vec<String>,
    pub focus: Option<CachedFocus>,
}

#[derive(Debug, Deserialize)]
pub struct FocusDto {
    pub id: String,
    pub name: Option<String>,
    pub allowed_domains: Vec<String>,
    pub expires_at: String,
}

#[derive(Debug, Deserialize)]
pub struct PolicyDto {
    pub version: String,
    pub server_time: String,
    #[serde(default)]
    pub unchanged: bool,
    #[serde(default)]
    pub block: Vec<String>,
    pub focus: Option<FocusDto>,
}

/// Converts the backend's absolute deadline into one on the local clock using the
/// backend's own idea of "now", so a device clock that is wrong can neither
/// extend nor cut short a focus session.
pub fn to_local(dto: &PolicyDto, now: DateTime<Utc>) -> Option<CachedPolicy> {
    let focus = match &dto.focus {
        None => None,
        Some(focus) => {
            let server_now = DateTime::parse_from_rfc3339(&dto.server_time).ok()?;
            let expires = DateTime::parse_from_rfc3339(&focus.expires_at).ok()?;
            let remaining: Duration = expires.signed_duration_since(server_now);
            Some(CachedFocus {
                id: focus.id.clone(),
                name: focus.name.clone(),
                allowed_domains: focus.allowed_domains.clone(),
                ends_at: (now + remaining).to_rfc3339(),
            })
        }
    };

    Some(CachedPolicy { version: dto.version.clone(), block: dto.block.clone(), focus })
}

/// Drops a focus session whose local deadline has passed, keeping the block list.
/// `None` means nothing changed.
pub fn expire_locally(policy: &CachedPolicy, now: DateTime<Utc>) -> Option<CachedPolicy> {
    let focus = policy.focus.as_ref()?;
    let ends_at = DateTime::parse_from_rfc3339(&focus.ends_at).ok()?;
    (ends_at <= now).then(|| CachedPolicy { focus: None, ..policy.clone() })
}

/// The `policy.update` message the extension understands.
pub fn message(policy: &CachedPolicy) -> String {
    let focus = policy.focus.as_ref().map(|focus| {
        json!({ "id": focus.id, "name": focus.name, "allowed_domains": focus.allowed_domains, "ends_at": focus.ends_at })
    });
    crate::browser_bridge::envelope(
        &Uuid::new_v4().to_string(),
        "policy.update",
        json!({ "version": policy.version, "block": policy.block, "focus": focus }),
    )
}

pub fn cached(state: &AppState) -> Option<CachedPolicy> {
    policy_repo::load(&state.db.lock().unwrap()).ok().flatten()
}

fn store_and_push(state: &AppState, policy: &CachedPolicy) {
    let _ = policy_repo::save(&state.db.lock().unwrap(), policy);
    state.bridge.broadcast(&message(policy));
}

/// Runs every tick, online or not: focus ends on the local clock.
pub fn enforce_local_expiry(state: &AppState) {
    if let Some(current) = cached(state) {
        if let Some(expired) = expire_locally(&current, Utc::now()) {
            store_and_push(state, &expired);
        }
    }
}

/// Fetches the policy if it changed and pushes it to the extension.
pub async fn sync(state: &AppState, base_url: &str, token: &str) {
    let known = cached(state).map(|policy| policy.version);
    let Ok(dto) = crate::api_client::fetch_policy(&state.http, base_url, token, known.as_deref()).await else { return };
    if dto.unchanged {
        return;
    }

    if let Some(policy) = to_local(&dto, Utc::now()) {
        store_and_push(state, &policy);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn dto(server_time: &str, expires_at: Option<&str>) -> PolicyDto {
        PolicyDto {
            version: "v1".into(),
            server_time: server_time.into(),
            unchanged: false,
            block: vec!["games.example".into()],
            focus: expires_at.map(|expires_at| FocusDto {
                id: "f1".into(),
                name: Some("Fractions".into()),
                allowed_domains: vec!["khan.example".into()],
                expires_at: expires_at.into(),
            }),
        }
    }

    fn at(text: &str) -> DateTime<Utc> {
        DateTime::parse_from_rfc3339(text).unwrap().with_timezone(&Utc)
    }

    #[test]
    fn the_deadline_is_measured_against_the_servers_clock_not_the_devices() {
        // The server says it is 09:00 and the session ends 09:20, so 20 minutes remain,
        // whatever this computer thinks the time is.
        let policy = to_local(&dto("2026-09-23T09:00:00+00:00", Some("2026-09-23T09:20:00+00:00")), at("2031-01-01T00:00:00Z")).unwrap();
        assert_eq!(at(&policy.focus.as_ref().unwrap().ends_at), at("2031-01-01T00:20:00Z"));

        let behind = to_local(&dto("2026-09-23T09:00:00+00:00", Some("2026-09-23T09:20:00+00:00")), at("2020-05-05T12:00:00Z")).unwrap();
        assert_eq!(at(&behind.focus.unwrap().ends_at), at("2020-05-05T12:20:00Z"));
    }

    #[test]
    fn a_policy_without_focus_keeps_only_the_block_list() {
        let policy = to_local(&dto("2026-09-23T09:00:00+00:00", None), Utc::now()).unwrap();
        assert_eq!(policy, CachedPolicy { version: "v1".into(), block: vec!["games.example".into()], focus: None });
    }

    #[test]
    fn a_focus_session_with_unreadable_times_is_refused_rather_than_guessed() {
        assert!(to_local(&dto("yesterday", Some("2026-09-23T09:20:00+00:00")), Utc::now()).is_none());
        assert!(to_local(&dto("2026-09-23T09:00:00+00:00", Some("soon")), Utc::now()).is_none());
    }

    #[test]
    fn focus_expires_locally_but_the_block_list_stays() {
        let policy = to_local(&dto("2026-09-23T09:00:00+00:00", Some("2026-09-23T09:20:00+00:00")), at("2026-09-23T09:00:00Z")).unwrap();

        assert!(expire_locally(&policy, at("2026-09-23T09:19:59Z")).is_none());
        let expired = expire_locally(&policy, at("2026-09-23T09:20:00Z")).unwrap();
        assert_eq!(expired.focus, None);
        assert_eq!(expired.block, vec!["games.example".to_string()]);
        assert!(expire_locally(&expired, at("2026-09-23T10:00:00Z")).is_none(), "nothing left to expire");
    }

    #[test]
    fn the_extension_message_carries_the_local_deadline() {
        let policy = to_local(&dto("2026-09-23T09:00:00+00:00", Some("2026-09-23T09:20:00+00:00")), at("2026-09-23T09:00:00Z")).unwrap();
        let line = message(&policy);
        assert!(line.ends_with('\n'));

        let value: serde_json::Value = serde_json::from_str(&line).unwrap();
        assert_eq!(value["type"], "policy.update");
        assert_eq!(value["version"], 1);
        assert_eq!(value["payload"]["block"], json!(["games.example"]));
        assert_eq!(value["payload"]["focus"]["allowed_domains"], json!(["khan.example"]));
        assert_eq!(at(value["payload"]["focus"]["ends_at"].as_str().unwrap()), at("2026-09-23T09:20:00Z"));
    }

    #[test]
    fn the_cache_round_trips_and_a_corrupt_row_reads_as_no_policy() {
        let conn = crate::db::open(std::path::Path::new(":memory:")).unwrap();
        assert_eq!(policy_repo::load(&conn).unwrap(), None);

        let policy = to_local(&dto("2026-09-23T09:00:00+00:00", Some("2026-09-23T09:20:00+00:00")), Utc::now()).unwrap();
        policy_repo::save(&conn, &policy).unwrap();
        assert_eq!(policy_repo::load(&conn).unwrap(), Some(policy.clone()));

        let newer = CachedPolicy { version: "v2".into(), ..policy };
        policy_repo::save(&conn, &newer).unwrap();
        assert_eq!(policy_repo::load(&conn).unwrap().unwrap().version, "v2");

        conn.execute("UPDATE policy_cache SET payload = 'not json'", []).unwrap();
        assert_eq!(policy_repo::load(&conn).unwrap(), None);
    }
}
