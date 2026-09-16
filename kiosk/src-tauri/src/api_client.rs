use crate::models::{ComputerMeResponse, RosterResponse, SyncSessionPayload, SyncSessionsRequestBody, SyncSessionsResponseBody};

fn join_url(base_url: &str, path: &str) -> String {
    format!("{}/{}", base_url.trim_end_matches('/'), path.trim_start_matches('/'))
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
    let url = join_url(base_url, "api/roster");
    let response = http
        .get(url)
        .bearer_auth(token)
        .send()
        .await
        .map_err(|e| e.to_string())?;

    if !response.status().is_success() {
        return Err(format!("server returned {}", response.status()));
    }

    response.json::<RosterResponse>().await.map_err(|e| e.to_string())
}

pub async fn post_sessions_sync(
    http: &reqwest::Client,
    base_url: &str,
    token: &str,
    sessions: Vec<SyncSessionPayload>,
) -> Result<SyncSessionsResponseBody, String> {
    let url = join_url(base_url, "api/sessions/sync");
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

    response.json::<SyncSessionsResponseBody>().await.map_err(|e| e.to_string())
}
