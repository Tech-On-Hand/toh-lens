use crate::models::{
    ApiEnvelope, ComputerMeResponse, EnrollmentData, EnrollmentRequest, HeartbeatData,
    HeartbeatRequest, RosterResponse, SyncSessionPayload, SyncSessionsRequestBody,
    SyncSessionsResponseBody,
};

fn join_url(base_url: &str, path: &str) -> String {
    format!("{}/{}", base_url.trim_end_matches('/'), path.trim_start_matches('/'))
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
        .map_err(|e| e.to_string())?;

    if !response.status().is_success() {
        return Err("The enrollment code is invalid, expired, or already used.".into());
    }

    response
        .json::<ApiEnvelope<EnrollmentData>>()
        .await
        .map_err(|e| e.to_string())?
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
        .map_err(|e| e.to_string())?;

    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }

    response
        .json::<ApiEnvelope<HeartbeatData>>()
        .await
        .map_err(|e| e.to_string())?
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
        .map_err(|e| e.to_string())?;

    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }

    response.json::<ComputerMeResponse>().await.map_err(|e| e.to_string())
}

pub async fn fetch_roster(http: &reqwest::Client, base_url: &str, token: &str) -> Result<RosterResponse, String> {
    let url = join_url(base_url, "api/v1/device/roster");
    let response = http
        .get(url)
        .bearer_auth(token)
        .send()
        .await
        .map_err(|e| e.to_string())?;

    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }

    response
        .json::<ApiEnvelope<RosterResponse>>()
        .await
        .map_err(|e| e.to_string())?
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
        .map_err(|e| e.to_string())?;

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
        .map_err(|e| e.to_string())?
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
        .map_err(|e| e.to_string())?;

    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }

    response
        .json::<ApiEnvelope<CommandsData>>()
        .await
        .map_err(|e| e.to_string())?
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
        .map_err(|e| e.to_string())?;

    if response.status().is_success() {
        Ok(())
    } else {
        Err(format!("server returned {}", response.status()))
    }
}
