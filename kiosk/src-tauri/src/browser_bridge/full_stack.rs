//! Drives the agent's real code against a real Laravel backend. Ignored by default;
//! run it through `e2e/full-stack.mjs`, which provisions a throwaway backend.

use super::*;
use crate::db::session_repo;
use crate::models::{HeartbeatRequest, StudentSummary};
use serde_json::Value;
use tokio::io::AsyncWriteExt;

fn var(name: &str) -> String {
    std::env::var(name).unwrap_or_else(|_| panic!("{name} is required; run e2e/full-stack.mjs"))
}

async fn until<F, Fut>(what: &str, mut check: F)
where
    F: FnMut() -> Fut,
    Fut: std::future::Future<Output = bool>,
{
    for _ in 0..150 {
        if check().await {
            return;
        }
        tokio::time::sleep(Duration::from_millis(100)).await;
    }
    panic!("timed out waiting for {what}");
}

async fn get(state: &AppState, url: String, token: &str) -> Value {
    state.http.get(url).bearer_auth(token).send().await.unwrap().json().await.unwrap()
}

async fn sync_sessions(state: &AppState, base: &str, token: &str) {
    let batch = session_repo::unsynced_batch(&state.db.lock().unwrap(), 200).unwrap();
    let response = crate::api_client::post_sessions_sync(&state.http, base, token, batch).await.unwrap();
    assert!(response.results.iter().all(|row| row.status == "synced"));
    let uuids: Vec<String> = response.results.into_iter().map(|row| row.uuid).collect();
    session_repo::mark_synced(&state.db.lock().unwrap(), &uuids).unwrap();
}

fn message(kind: &str, payload: Value) -> String {
    format!("{}\n", json!({ "version": 1, "id": Uuid::new_v4().to_string(), "type": kind, "payload": payload }))
}

#[tokio::test]
#[ignore = "needs a running backend; see e2e/full-stack.mjs"]
async fn the_agent_and_backend_agree_end_to_end() {
    let base = var("TOH_E2E_BASE_URL");
    let device_token = var("TOH_E2E_DEVICE_TOKEN");
    let computer_id: i64 = var("TOH_E2E_COMPUTER_ID").parse().unwrap();
    let classroom_id = var("TOH_E2E_CLASSROOM_ID");
    let admission = var("TOH_E2E_ADMISSION");

    let conn = crate::db::open(std::path::Path::new(":memory:")).unwrap();
    config_repo::save_provisioning(&conn, &base, "", 1, computer_id, "E2E PC", None, None, 1).unwrap();
    let state = AppState::new(conn);
    let dir = std::env::temp_dir().join(format!("toh-klas-fullstack-{}", Uuid::new_v4()));
    let port = start_in(state.clone(), dir.clone()).await.unwrap();
    let bridge_token = state.bridge.inner.token.lock().unwrap().clone();

    // The device announces itself, then a student signs in and the session syncs.
    crate::api_client::heartbeat(
        &state.http,
        &base,
        &device_token,
        HeartbeatRequest { hostname: "E2E-PC".into(), operating_system: "Windows x86_64".into(), agent_version: "0.0.0-e2e".into() },
    )
    .await
    .unwrap();

    let session = {
        let conn = state.db.lock().unwrap();
        let student = StudentSummary { id: 1, admission_number: admission.clone(), full_name: "E2E Student".into() };
        session_repo::insert_login(&conn, 1, computer_id, &student).unwrap().session_uuid
    };
    sync_sessions(&state, &base, &device_token).await;

    // A fake extension connects to the real bridge and reports what the student is doing.
    let stream = TcpStream::connect(("127.0.0.1", port)).await.unwrap();
    let (read_half, mut write_half) = stream.into_split();
    let mut extension = BufReader::new(read_half);
    write_half.write_all(format!("{}\n", json!({ "type": "bridge.auth", "token": bridge_token })).as_bytes()).await.unwrap();
    write_half.write_all(message("browser.hello", json!({ "browser": "chrome" })).as_bytes()).await.unwrap();
    write_half
        .write_all(
            message(
                "browser.snapshot",
                json!({
                    "observed_at": Utc::now().to_rfc3339(),
                    "tabs": [
                        { "tab_id": 11, "window_id": 1, "url": "https://khan.example/lesson?x=1", "title": "Lesson", "active": true },
                        { "tab_id": 12, "window_id": 1, "url": "https://example.org/", "title": "Other", "active": false }
                    ]
                }),
            )
            .as_bytes(),
        )
        .await
        .unwrap();
    write_half
        .write_all(
            message(
                "browser.activity",
                json!({ "browser": "chrome", "events": [
                    { "uuid": Uuid::new_v4().to_string(), "type": "navigated", "url": "https://khan.example/lesson?x=1", "title": "Lesson", "occurred_at": Utc::now().to_rfc3339() }
                ] }),
            )
            .as_bytes(),
        )
        .await
        .unwrap();

    let bridge = state.bridge.clone();
    until("the agent to hold the snapshot and the event", || {
        let (bridge, state) = (bridge.clone(), state.clone());
        async move {
            bridge.inner.snapshots.lock().unwrap().contains_key("chrome")
                && !browser_repo::pending_events(&state.db.lock().unwrap(), "chrome", 10).unwrap().is_empty()
        }
    })
    .await;

    flush_browser_data(&state, &base, &device_token).await;
    assert!(
        browser_repo::pending_events(&state.db.lock().unwrap(), "chrome", 10).unwrap().is_empty(),
        "the backend must accept the agent's events so they leave the offline queue"
    );
    assert!(!bridge.inner.snapshots.lock().unwrap()["chrome"].dirty, "the backend must accept the snapshot");

    // The teacher sees the student and the active tab, and issues a command.
    let login: Value = state
        .http
        .post(format!("{base}/api/v1/auth/login"))
        .json(&json!({ "email": var("TOH_E2E_TEACHER_EMAIL"), "password": var("TOH_E2E_TEACHER_PASSWORD") }))
        .send()
        .await
        .unwrap()
        .json()
        .await
        .unwrap();
    let teacher = login["data"]["token"].as_str().expect("teacher login").to_string();

    let devices = get(&state, format!("{base}/api/v1/teacher/classrooms/{classroom_id}/devices"), &teacher).await;
    let device = devices["data"].as_array().unwrap().iter().find(|d| d["id"] == computer_id).expect("device in the classroom snapshot").clone();
    assert_eq!(device["active_tab"]["url"], "https://khan.example/lesson?x=1");
    assert_eq!(device["active_session"]["uuid"], session.as_str());
    assert_eq!(device["active_session"]["student"]["admission_number"], admission.as_str());

    let tabs = get(&state, format!("{base}/api/v1/teacher/classrooms/{classroom_id}/devices/{computer_id}/browser-tabs"), &teacher).await;
    assert_eq!(tabs["data"].as_array().unwrap().len(), 2);

    let command_url = format!("{base}/api/v1/teacher/classrooms/{classroom_id}/devices/{computer_id}/commands");
    let issued: Value = state
        .http
        .post(&command_url)
        .bearer_auth(&teacher)
        .json(&json!({ "type": "browser.open_url", "student_session_id": session, "payload": { "url": "https://example.com/next", "browser": "chrome" } }))
        .send()
        .await
        .unwrap()
        .json()
        .await
        .unwrap();
    let command_id = issued["data"]["id"].as_str().expect("command issued").to_string();

    // The agent polls, forwards it to the extension, and reports the extension's answer.
    poll_commands(&state, &base, &device_token).await;
    let mut line = String::new();
    tokio::time::timeout(Duration::from_secs(10), extension.read_line(&mut line))
        .await
        .expect("command reached the extension")
        .unwrap();
    let request: Value = serde_json::from_str(&line).unwrap();
    assert_eq!(request["type"], "browser.open_url");
    assert_eq!(request["id"], command_id.as_str());
    assert_eq!(request["payload"]["url"], "https://example.com/next");
    let reply = json!({ "version": 1, "id": request["id"], "type": "response", "payload": { "status": "completed", "result": { "tab_id": 42 } } });
    write_half.write_all(format!("{reply}\n").as_bytes()).await.unwrap();

    until("the backend to record the result", || {
        let (state, teacher, url) = (state.clone(), teacher.clone(), format!("{command_url}/{command_id}"));
        async move { get(&state, url, &teacher).await["data"]["status"] == "completed" }
    })
    .await;
    let finished = get(&state, format!("{command_url}/{command_id}"), &teacher).await;
    assert_eq!(finished["data"]["result"]["tab_id"], 42);

    // After sign-out the same session can no longer be commanded.
    {
        let conn = state.db.lock().unwrap();
        session_repo::close_logout(&conn, &session).unwrap();
    }
    sync_sessions(&state, &base, &device_token).await;
    let stale = state
        .http
        .post(&command_url)
        .bearer_auth(&teacher)
        .json(&json!({ "type": "browser.open_url", "student_session_id": session, "payload": { "url": "https://example.com/late" } }))
        .send()
        .await
        .unwrap();
    assert_eq!(stale.status().as_u16(), 409);
    assert_eq!(stale.json::<Value>().await.unwrap()["error"]["code"], "SESSION_MISMATCH");

    let _ = std::fs::remove_dir_all(dir);
}
