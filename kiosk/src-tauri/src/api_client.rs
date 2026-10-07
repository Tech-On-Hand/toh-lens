use crate::models::{
    ApiEnvelope, ComputerMeResponse, EnrollmentData, EnrollmentRequest, HeartbeatData,
    HeartbeatRequest, RosterResponse, SyncSessionPayload, SyncSessionsRequestBody,
    SyncSessionsResponseBody,
};

fn join_url(base_url: &str, path: &str) -> String {
    format!("{}/{}", base_url.trim_end_matches('/'), path.trim_start_matches('/'))
}

/// One authenticated call to a `/api/v1/device/*` endpoint that just returns the
/// envelope's `data`. Used by the communication commands (announcements, help
/// requests), which need no bespoke request shape of their own.
pub async fn device_json(
    http: &reqwest::Client,
    base_url: &str,
    token: &str,
    method: reqwest::Method,
    path: &str,
    body: Option<serde_json::Value>,
) -> Result<serde_json::Value, String> {
    let mut request = http.request(method, join_url(base_url, &format!("api/v1/device/{path}"))).bearer_auth(token);
    if let Some(body) = body {
        request = request.json(&body);
    }
    let response = request.send().await.map_err(describe)?;

    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }
    response
        .json::<ApiEnvelope<serde_json::Value>>()
        .await
        .map_err(describe)?
        .data
        .ok_or_else(|| "The server returned an invalid response.".into())
}

pub async fn enroll_device(
    http: &reqwest::Client,
    base_url: &str,
    request: EnrollmentRequest,
) -> Result<EnrollmentData, String> {
    let response = http
        .post(join_url(base_url, "api/v1/devices/enroll"))
        .json(&request)
        .send()
        .await
        .map_err(describe)?;

    if !response.status().is_success() {
        return Err("The enrollment code is invalid, expired, or already used.".into());
    }

    response
        .json::<ApiEnvelope<EnrollmentData>>()
        .await
        .map_err(describe)?
        .data
        .ok_or_else(|| "The server returned an invalid enrollment response.".into())
}

pub async fn heartbeat(
    http: &reqwest::Client,
    base_url: &str,
    token: &str,
    request: HeartbeatRequest,
) -> Result<HeartbeatData, String> {
    let response = http
        .post(join_url(base_url, "api/v1/device/heartbeat"))
        .bearer_auth(token)
        .json(&request)
        .send()
        .await
        .map_err(describe)?;

    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }

    response
        .json::<ApiEnvelope<HeartbeatData>>()
        .await
        .map_err(describe)?
        .data
        .ok_or_else(|| "The server returned an invalid heartbeat response.".into())
}

/// Cheap, unauthenticated connectivity probe with a short timeout so the
/// background sync loop can decide "online or not" without waiting long.
pub async fn ping(http: &reqwest::Client, base_url: &str) -> bool {
    let url = join_url(base_url, "api/ping");
    match http
        .get(url)
        .timeout(std::time::Duration::from_secs(5))
        .send()
        .await
    {
        Ok(response) => response.status().is_success(),
        Err(_) => false,
    }
}

pub async fn fetch_computer_me(http: &reqwest::Client, base_url: &str, token: &str) -> Result<ComputerMeResponse, String> {
    let url = join_url(base_url, "api/computer/me");
    let response = http
        .get(url)
        .bearer_auth(token)
        .send()
        .await
        .map_err(describe)?;

    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }

    response.json::<ComputerMeResponse>().await.map_err(describe)
}

pub async fn fetch_roster(http: &reqwest::Client, base_url: &str, token: &str) -> Result<RosterResponse, String> {
    let url = join_url(base_url, "api/v1/device/roster");
    let response = http
        .get(url)
        .bearer_auth(token)
        .send()
        .await
        .map_err(describe)?;

    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }

    response
        .json::<ApiEnvelope<RosterResponse>>()
        .await
        .map_err(describe)?
        .data
        .ok_or_else(|| "The server returned an invalid roster response.".into())
}

pub async fn post_sessions_sync(
    http: &reqwest::Client,
    base_url: &str,
    token: &str,
    sessions: Vec<SyncSessionPayload>,
) -> Result<SyncSessionsResponseBody, String> {
    let url = join_url(base_url, "api/v1/device/student-sessions/sync");
    let body = SyncSessionsRequestBody { sessions };

    let response = http
        .post(url)
        .bearer_auth(token)
        .json(&body)
        .send()
        .await
        .map_err(describe)?;

    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }

    #[derive(serde::Deserialize)]
    struct SyncData {
        results: Vec<crate::models::SyncResultRow>,
    }

    let data = response
        .json::<ApiEnvelope<SyncData>>()
        .await
        .map_err(describe)?
        .data
        .ok_or_else(|| "The server returned an invalid sync response.".to_string())?;

    Ok(SyncSessionsResponseBody { results: data.results })
}

/// `Err(Some(status))` is an HTTP rejection, `Err(None)` a network failure, so
/// the caller can tell "retry later" from "this payload will never be accepted".
pub async fn post_browser_events(
    http: &reqwest::Client,
    base_url: &str,
    token: &str,
    body: &serde_json::Value,
) -> Result<(), Option<u16>> {
    let response = http
        .post(join_url(base_url, "api/v1/device/browser/events"))
        .bearer_auth(token)
        .json(body)
        .send()
        .await
        .map_err(|_| None)?;

    if response.status().is_success() {
        Ok(())
    } else {
        Err(Some(response.status().as_u16()))
    }
}

pub async fn fetch_commands(
    http: &reqwest::Client,
    base_url: &str,
    token: &str,
) -> Result<Vec<serde_json::Value>, String> {
    #[derive(serde::Deserialize)]
    struct CommandsData {
        commands: Vec<serde_json::Value>,
    }

    let response = http
        .get(join_url(base_url, "api/v1/device/commands"))
        .bearer_auth(token)
        .send()
        .await
        .map_err(describe)?;

    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }

    response
        .json::<ApiEnvelope<CommandsData>>()
        .await
        .map_err(describe)?
        .data
        .map(|data| data.commands)
        .ok_or_else(|| "The server returned an invalid commands response.".into())
}

pub async fn post_command_result(
    http: &reqwest::Client,
    base_url: &str,
    token: &str,
    command_id: &str,
    status: &str,
    result: serde_json::Value,
) -> Result<(), String> {
    let response = http
        .post(join_url(base_url, &format!("api/v1/device/commands/{command_id}/result")))
        .bearer_auth(token)
        .json(&serde_json::json!({ "status": status, "result": result }))
        .send()
        .await
        .map_err(describe)?;

    if response.status().is_success() {
        Ok(())
    } else {
        Err(format!("server returned {}", response.status()))
    }
}

/// Pass the last known `version` to get a tiny "unchanged" answer when nothing moved.
pub async fn fetch_policy(
    http: &reqwest::Client,
    base_url: &str,
    token: &str,
    known: Option<&str>,
) -> Result<crate::policy_sync::PolicyDto, String> {
    let mut request = http.get(join_url(base_url, "api/v1/device/policy")).bearer_auth(token);
    if let Some(version) = known {
        request = request.query(&[("known", version)]);
    }

    let response = request.send().await.map_err(describe)?;
    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }

    response
        .json::<ApiEnvelope<crate::policy_sync::PolicyDto>>()
        .await
        .map_err(describe)?
        .data
        .ok_or_else(|| "The server returned an invalid policy response.".into())
}

pub async fn fetch_current_screen_session(
    http: &reqwest::Client,
    base_url: &str,
    token: &str,
    after: i64,
) -> Result<Option<serde_json::Value>, String> {
    let response = http
        .get(join_url(base_url, "api/v1/device/screen-sessions/current"))
        .bearer_auth(token)
        .query(&[("after", after)])
        .send()
        .await
        .map_err(describe)?;

    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }

    #[derive(serde::Deserialize)]
    struct CurrentData {
        session: Option<serde_json::Value>,
    }

    response
        .json::<ApiEnvelope<CurrentData>>()
        .await
        .map_err(describe)?
        .data
        .map(|data| data.session)
        .ok_or_else(|| "The server returned an invalid screen-session response.".into())
}

pub async fn answer_screen_session(
    http: &reqwest::Client,
    base_url: &str,
    token: &str,
    session_id: &str,
    answer: &serde_json::Value,
) -> Result<(), String> {
    let response = http
        .patch(join_url(base_url, &format!("api/v1/device/screen-sessions/{session_id}")))
        .bearer_auth(token)
        .json(&serde_json::json!({ "answer": answer }))
        .send()
        .await
        .map_err(describe)?;

    if response.status().is_success() {
        Ok(())
    } else {
        Err(format!("server returned {}", response.status()))
    }
}

pub async fn post_screen_session_candidate(
    http: &reqwest::Client,
    base_url: &str,
    token: &str,
    session_id: &str,
    candidate: &serde_json::Value,
) -> Result<(), String> {
    let response = http
        .post(join_url(base_url, &format!("api/v1/device/screen-sessions/{session_id}/candidates")))
        .bearer_auth(token)
        .json(&serde_json::json!({ "candidates": [candidate] }))
        .send()
        .await
        .map_err(describe)?;

    if response.status().is_success() {
        Ok(())
    } else {
        Err(format!("server returned {}", response.status()))
    }
}

// --- teacher-broadcast: receiving-kiosk (target) role, used by the kiosk's
// own frontend via Tauri commands (see commands.rs) — this device plays the
// same "viewer" role a teacher plays watching a device, just kiosk-to-kiosk.

pub async fn fetch_broadcast_status(http: &reqwest::Client, base_url: &str, token: &str, after: i64) -> Result<serde_json::Value, String> {
    let response = http
        .get(join_url(base_url, "api/v1/device/broadcasts/current"))
        .bearer_auth(token)
        .query(&[("after", after)])
        .send()
        .await
        .map_err(describe)?;

    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }
    response
        .json::<ApiEnvelope<serde_json::Value>>()
        .await
        .map_err(describe)?
        .data
        .ok_or_else(|| "The server returned an invalid broadcast response.".into())
}

pub async fn join_broadcast(http: &reqwest::Client, base_url: &str, token: &str, broadcast_id: &str, offer: &serde_json::Value) -> Result<serde_json::Value, String> {
    let response = http
        .post(join_url(base_url, &format!("api/v1/device/broadcasts/{broadcast_id}/join")))
        .bearer_auth(token)
        .json(&serde_json::json!({ "offer": offer }))
        .send()
        .await
        .map_err(describe)?;

    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }
    response
        .json::<ApiEnvelope<serde_json::Value>>()
        .await
        .map_err(describe)?
        .data
        .ok_or_else(|| "The server returned an invalid join response.".into())
}

/// Shared by both sides of a broadcast target connection: which one posted a
/// given candidate is inferred server-side from which device is authenticated.
pub async fn post_broadcast_target_candidate(http: &reqwest::Client, base_url: &str, token: &str, target_id: &str, candidate: &serde_json::Value) -> Result<(), String> {
    let response = http
        .post(join_url(base_url, &format!("api/v1/device/broadcasts/targets/{target_id}/candidates")))
        .bearer_auth(token)
        .json(&serde_json::json!({ "candidates": [candidate] }))
        .send()
        .await
        .map_err(describe)?;

    if response.status().is_success() {
        Ok(())
    } else {
        Err(format!("server returned {}", response.status()))
    }
}

// --- teacher-broadcast: source-kiosk role, used internally by screen_broadcast.rs.

pub async fn fetch_outgoing_broadcast(http: &reqwest::Client, base_url: &str, token: &str) -> Result<serde_json::Value, String> {
    let response = http
        .get(join_url(base_url, "api/v1/device/broadcasts/outgoing"))
        .bearer_auth(token)
        .send()
        .await
        .map_err(describe)?;

    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }
    response
        .json::<ApiEnvelope<serde_json::Value>>()
        .await
        .map_err(describe)?
        .data
        .ok_or_else(|| "The server returned an invalid broadcast response.".into())
}

pub async fn answer_broadcast_target(http: &reqwest::Client, base_url: &str, token: &str, target_id: &str, answer: &serde_json::Value) -> Result<(), String> {
    let response = http
        .patch(join_url(base_url, &format!("api/v1/device/broadcasts/targets/{target_id}")))
        .bearer_auth(token)
        .json(&serde_json::json!({ "answer": answer }))
        .send()
        .await
        .map_err(describe)?;

    if response.status().is_success() {
        Ok(())
    } else {
        Err(format!("server returned {}", response.status()))
    }
}

/// reqwest's own message is only "error sending request for url (...)". The part that
/// says what went wrong (DNS, refused connection, bad certificate, timeout) is further
/// down the source chain, so include it.
fn describe(e: reqwest::Error) -> String {
    let mut message = e.to_string();
    let mut source = std::error::Error::source(&e);
    while let Some(cause) = source {
        message.push_str(": ");
        message.push_str(&cause.to_string());
        source = cause.source();
    }
    message
}
