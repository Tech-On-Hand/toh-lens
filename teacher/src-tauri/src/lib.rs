use futures_util::{SinkExt, StreamExt};
use serde::{de::DeserializeOwned, Deserialize, Serialize};
use std::sync::{Arc, Mutex};
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::Duration;
use tauri::{Emitter, State};
use tokio_tungstenite::tungstenite::Message;

const CREDENTIAL_SERVICE: &str = "com.techonhand.toh-klas.teacher";

#[derive(Clone, Debug, Serialize, Deserialize)]
struct UserProfile {
    id: i64,
    name: String,
    email: String,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
struct RealtimeConfig {
    key: String,
    host: String,
    port: u16,
    scheme: String,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
struct TeacherSession {
    user: UserProfile,
    is_administrator: bool,
}

#[derive(Clone, Debug, Deserialize)]
struct LoginData {
    token: String,
    user: UserProfile,
    is_administrator: bool,
    realtime: RealtimeConfig,
}

#[derive(Clone)]
struct StoredSession {
    api_base_url: String,
    token: String,
    profile: TeacherSession,
    realtime: RealtimeConfig,
}

struct AppState {
    http: reqwest::Client,
    session: Arc<Mutex<Option<StoredSession>>>,
    realtime_generation: Arc<AtomicU64>,
}

fn credential(account: &str) -> Result<keyring::Entry, String> {
    keyring::Entry::new(CREDENTIAL_SERVICE, account).map_err(|e| e.to_string())
}

fn save_credentials(session: &StoredSession) -> Result<(), String> {
    credential("token")?.set_password(&session.token).map_err(|e| e.to_string())?;
    credential("api-base-url")?.set_password(&session.api_base_url).map_err(|e| e.to_string())?;
    credential("profile")?.set_password(&serde_json::to_string(&session.profile).map_err(|e| e.to_string())?).map_err(|e| e.to_string())?;
    credential("realtime")?.set_password(&serde_json::to_string(&session.realtime).map_err(|e| e.to_string())?).map_err(|e| e.to_string())
}

fn load_credentials() -> Option<StoredSession> {
    Some(StoredSession {
        token: credential("token").ok()?.get_password().ok()?,
        api_base_url: credential("api-base-url").ok()?.get_password().ok()?,
        profile: serde_json::from_str(&credential("profile").ok()?.get_password().ok()?).ok()?,
        realtime: serde_json::from_str(&credential("realtime").ok()?.get_password().ok()?).ok()?,
    })
}

fn clear_credentials() {
    for account in ["token", "api-base-url", "profile", "realtime"] {
        if let Ok(entry) = credential(account) { let _ = entry.delete_credential(); }
    }
}

fn endpoint(base: &str, path: &str) -> String {
    format!("{}/{}", base.trim_end_matches('/'), path.trim_start_matches('/'))
}

async fn response_data<T: DeserializeOwned>(response: reqwest::Response) -> Result<T, String> {
    let status = response.status();
    let body: serde_json::Value = response.json().await.unwrap_or(serde_json::Value::Null);
    if !status.is_success() || body["success"] != true {
        // Business errors use {error:{message}}; framework errors (403, 422) use {message}.
        let message = body["error"]["message"].as_str().or_else(|| body["message"].as_str()).filter(|m| !m.is_empty());
        return Err(message.map(str::to_string).unwrap_or_else(|| match status.as_u16() {
            403 => "You do not have permission to do that.".to_string(),
            _ => format!("Server returned {status}"),
        }));
    }
    serde_json::from_value(body["data"].clone()).map_err(|_| "Server returned no data.".to_string())
}

fn current_session(state: &AppState) -> Result<StoredSession, String> {
    state.session.lock().map_err(|_| "Session lock failed".to_string())?.clone().ok_or_else(|| "Please sign in again.".into())
}

#[tauri::command]
async fn login(state: State<'_, AppState>, api_base_url: String, email: String, password: String) -> Result<TeacherSession, String> {
    let response = state.http.post(endpoint(&api_base_url, "api/v1/auth/login"))
        .json(&serde_json::json!({ "email": email, "password": password, "device_name": "TOH Klas Teacher" }))
        .send().await.map_err(|e| e.to_string())?;
    let data: LoginData = response_data(response).await?;
    let profile = TeacherSession { user: data.user, is_administrator: data.is_administrator };
    let stored = StoredSession { api_base_url, token: data.token, profile: profile.clone(), realtime: data.realtime };
    save_credentials(&stored)?;
    *state.session.lock().map_err(|_| "Session lock failed".to_string())? = Some(stored);
    Ok(profile)
}

#[tauri::command]
async fn restore_session(state: State<'_, AppState>) -> Result<Option<TeacherSession>, String> {
    let Some(stored) = load_credentials() else { return Ok(None) };
    let response = state.http.get(endpoint(&stored.api_base_url, "api/v1/teacher/classrooms"))
        .bearer_auth(&stored.token).send().await.map_err(|e| e.to_string())?;
    if !response.status().is_success() { clear_credentials(); return Ok(None); }
    let profile = stored.profile.clone();
    *state.session.lock().map_err(|_| "Session lock failed".to_string())? = Some(stored);
    Ok(Some(profile))
}

#[tauri::command]
async fn logout(state: State<'_, AppState>) -> Result<(), String> {
    if let Ok(session) = current_session(&state) {
        let _ = state.http.post(endpoint(&session.api_base_url, "api/v1/auth/logout")).bearer_auth(&session.token).send().await;
    }
    state.realtime_generation.fetch_add(1, Ordering::SeqCst);
    *state.session.lock().map_err(|_| "Session lock failed".to_string())? = None;
    clear_credentials();
    Ok(())
}

async fn authenticated_get<T: DeserializeOwned>(state: &AppState, path: &str) -> Result<T, String> {
    let session = current_session(state)?;
    let response = state.http.get(endpoint(&session.api_base_url, path)).bearer_auth(&session.token).send().await.map_err(|e| e.to_string())?;
    response_data(response).await
}

#[tauri::command]
async fn list_classrooms(state: State<'_, AppState>) -> Result<serde_json::Value, String> {
    authenticated_get(&state, "api/v1/teacher/classrooms").await
}

#[tauri::command]
async fn list_devices(state: State<'_, AppState>, classroom_id: i64) -> Result<serde_json::Value, String> {
    authenticated_get(&state, &format!("api/v1/teacher/classrooms/{classroom_id}/devices")).await
}

#[tauri::command]
async fn list_browser_tabs(state: State<'_, AppState>, classroom_id: i64, device_id: i64) -> Result<serde_json::Value, String> {
    authenticated_get(&state, &format!("api/v1/teacher/classrooms/{classroom_id}/devices/{device_id}/browser-tabs")).await
}

#[tauri::command]
async fn send_command(state: State<'_, AppState>, classroom_id: i64, device_id: i64, command: serde_json::Value) -> Result<serde_json::Value, String> {
    let session = current_session(&state)?;
    let response = state.http.post(endpoint(&session.api_base_url, &format!("api/v1/teacher/classrooms/{classroom_id}/devices/{device_id}/commands")))
        .bearer_auth(&session.token).json(&command).send().await.map_err(|e| e.to_string())?;
    response_data(response).await
}

#[tauri::command]
async fn get_command(state: State<'_, AppState>, classroom_id: i64, device_id: i64, command_id: String) -> Result<serde_json::Value, String> {
    authenticated_get(&state, &format!("api/v1/teacher/classrooms/{classroom_id}/devices/{device_id}/commands/{command_id}")).await
}

async fn authenticated_send(state: &AppState, method: reqwest::Method, path: &str, body: Option<serde_json::Value>) -> Result<serde_json::Value, String> {
    let session = current_session(state)?;
    let mut request = state.http.request(method, endpoint(&session.api_base_url, path)).bearer_auth(&session.token);
    if let Some(body) = body {
        request = request.json(&body);
    }
    response_data(request.send().await.map_err(|e| e.to_string())?).await
}

#[tauri::command]
async fn get_policy(state: State<'_, AppState>, classroom_id: i64) -> Result<serde_json::Value, String> {
    authenticated_get(&state, &format!("api/v1/teacher/classrooms/{classroom_id}/policy")).await
}

#[tauri::command]
async fn start_focus(state: State<'_, AppState>, classroom_id: i64, allowed_domains: Vec<String>, duration_minutes: u32, name: Option<String>) -> Result<serde_json::Value, String> {
    let body = serde_json::json!({ "allowed_domains": allowed_domains, "duration_minutes": duration_minutes, "name": name });
    authenticated_send(&state, reqwest::Method::POST, &format!("api/v1/teacher/classrooms/{classroom_id}/focus-sessions"), Some(body)).await
}

#[tauri::command]
async fn end_focus(state: State<'_, AppState>, classroom_id: i64, focus_id: String) -> Result<serde_json::Value, String> {
    authenticated_send(&state, reqwest::Method::POST, &format!("api/v1/teacher/classrooms/{classroom_id}/focus-sessions/{focus_id}/end"), None).await
}

#[tauri::command]
async fn add_block_rule(state: State<'_, AppState>, classroom_id: i64, domain: String) -> Result<serde_json::Value, String> {
    authenticated_send(&state, reqwest::Method::POST, &format!("api/v1/teacher/classrooms/{classroom_id}/block-rules"), Some(serde_json::json!({ "domain": domain }))).await
}

#[tauri::command]
async fn remove_block_rule(state: State<'_, AppState>, classroom_id: i64, rule_id: i64) -> Result<serde_json::Value, String> {
    authenticated_send(&state, reqwest::Method::DELETE, &format!("api/v1/teacher/classrooms/{classroom_id}/block-rules/{rule_id}"), None).await
}

#[tauri::command]
async fn revoke_device(state: State<'_, AppState>, device_id: i64) -> Result<(), String> {
    let session = current_session(&state)?;
    let response = state.http.post(endpoint(&session.api_base_url, &format!("api/v1/admin/devices/{device_id}/revoke")))
        .bearer_auth(&session.token).send().await.map_err(|e| e.to_string())?;
    if response.status().is_success() { Ok(()) } else { Err("Device revocation was denied.".into()) }
}

#[tauri::command]
async fn start_screen_session(state: State<'_, AppState>, classroom_id: i64, device_id: i64, offer: serde_json::Value) -> Result<serde_json::Value, String> {
    authenticated_send(
        &state, reqwest::Method::POST,
        &format!("api/v1/teacher/classrooms/{classroom_id}/devices/{device_id}/screen-sessions"),
        Some(serde_json::json!({ "offer": offer })),
    ).await
}

#[tauri::command]
async fn poll_screen_session(state: State<'_, AppState>, classroom_id: i64, device_id: i64, session_id: String, after: i64) -> Result<serde_json::Value, String> {
    authenticated_get(&state, &format!("api/v1/teacher/classrooms/{classroom_id}/devices/{device_id}/screen-sessions/{session_id}?after={after}")).await
}

#[tauri::command]
async fn send_screen_candidates(state: State<'_, AppState>, classroom_id: i64, device_id: i64, session_id: String, candidates: Vec<serde_json::Value>) -> Result<serde_json::Value, String> {
    authenticated_send(
        &state, reqwest::Method::POST,
        &format!("api/v1/teacher/classrooms/{classroom_id}/devices/{device_id}/screen-sessions/{session_id}/candidates"),
        Some(serde_json::json!({ "candidates": candidates })),
    ).await
}

#[tauri::command]
async fn set_screen_quality(state: State<'_, AppState>, classroom_id: i64, device_id: i64, session_id: String, quality: String) -> Result<serde_json::Value, String> {
    authenticated_send(
        &state, reqwest::Method::POST,
        &format!("api/v1/teacher/classrooms/{classroom_id}/devices/{device_id}/screen-sessions/{session_id}/quality"),
        Some(serde_json::json!({ "quality": quality })),
    ).await
}

#[tauri::command]
async fn end_screen_session(state: State<'_, AppState>, classroom_id: i64, device_id: i64, session_id: String) -> Result<serde_json::Value, String> {
    authenticated_send(
        &state, reqwest::Method::POST,
        &format!("api/v1/teacher/classrooms/{classroom_id}/devices/{device_id}/screen-sessions/{session_id}/end"),
        None,
    ).await
}

#[tauri::command]
async fn start_realtime(app: tauri::AppHandle, state: State<'_, AppState>, classroom_id: i64) -> Result<(), String> {
    let session = current_session(&state)?;
    let generation = state.realtime_generation.fetch_add(1, Ordering::SeqCst) + 1;
    let marker = state.realtime_generation.clone();
    let http = state.http.clone();

    tauri::async_runtime::spawn(async move {
        let mut delay = 1u64;
        while marker.load(Ordering::SeqCst) == generation {
            let protocol = if session.realtime.scheme == "https" { "wss" } else { "ws" };
            let url = format!("{protocol}://{}:{}/app/{}?protocol=7&client=toh-klas-teacher&version=0.1", session.realtime.host, session.realtime.port, session.realtime.key);
            let _ = app.emit("realtime-state", "connecting");
            if let Ok((mut socket, _)) = tokio_tungstenite::connect_async(&url).await {
                if let Some(Ok(Message::Text(message))) = socket.next().await {
                    if let Ok(envelope) = serde_json::from_str::<serde_json::Value>(&message) {
                        let socket_data = envelope.get("data").and_then(|v| v.as_str()).and_then(|value| serde_json::from_str::<serde_json::Value>(value).ok());
                        if let Some(socket_id) = socket_data.as_ref().and_then(|v| v.get("socket_id")).and_then(|v| v.as_str()) {
                            let channel = format!("private-classroom.{classroom_id}");
                            let auth_response = http.post(endpoint(&session.api_base_url, "api/v1/broadcasting/auth"))
                                .bearer_auth(&session.token)
                                .form(&[("socket_id", socket_id), ("channel_name", channel.as_str())])
                                .send().await;
                            if let Ok(response) = auth_response {
                                if let Ok(auth) = response.json::<serde_json::Value>().await {
                                    let subscribe = serde_json::json!({"event":"pusher:subscribe","data":{"auth":auth.get("auth").and_then(|v| v.as_str()).unwrap_or_default(),"channel":channel}});
                                    if socket.send(Message::Text(subscribe.to_string().into())).await.is_ok() {
                                        delay = 1;
                                        let _ = app.emit("realtime-state", "live");
                                        while marker.load(Ordering::SeqCst) == generation {
                                            match socket.next().await {
                                                Some(Ok(Message::Text(text))) => {
                                                    if let Ok(event) = serde_json::from_str::<serde_json::Value>(&text) {
                                                        let name = event.get("event").and_then(|v| v.as_str()).unwrap_or_default();
                                                        if !name.starts_with("pusher:") { let _ = app.emit("classroom-event", event); }
                                                    }
                                                }
                                                Some(Ok(Message::Ping(payload))) => { let _ = socket.send(Message::Pong(payload)).await; }
                                                Some(Ok(_)) => {}
                                                _ => break,
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            let _ = app.emit("realtime-state", "reconnecting");
            tokio::time::sleep(Duration::from_secs(delay)).await;
            delay = (delay * 2).min(30);
        }
    });
    Ok(())
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .manage(AppState {
            http: reqwest::Client::builder().timeout(Duration::from_secs(15)).build().expect("HTTP client"),
            session: Arc::new(Mutex::new(None)),
            realtime_generation: Arc::new(AtomicU64::new(0)),
        })
        .invoke_handler(tauri::generate_handler![login, restore_session, logout, list_classrooms, list_devices, list_browser_tabs, send_command, get_command, get_policy, start_focus, end_focus, add_block_rule, remove_block_rule, revoke_device, start_realtime, start_screen_session, poll_screen_session, send_screen_candidates, set_screen_quality, end_screen_session])
        .run(tauri::generate_context!())
        .expect("error while running TOH Klas Teacher");
}
