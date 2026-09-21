//! Local bridge between the browser extension and the backend.
//!
//! The extension talks to a tiny native messaging host, which relays newline-
//! delimited JSON to this loopback listener. Everything the student does in the
//! browser is stamped here with the student session that was open at the time,
//! queued in SQLite, and synced to the backend; commands travel the other way.

use crate::db::{browser_repo, config_repo, session_repo};
use crate::state::AppState;
use chrono::{DateTime, Utc};
use serde_json::{json, Value};
use std::collections::HashMap;
use std::io;
use std::path::PathBuf;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;
use tokio::io::{AsyncBufRead, AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufReader};
use tokio::net::{TcpListener, TcpStream};
use tokio::sync::{mpsc, oneshot};
use uuid::Uuid;

const MAX_LINE_BYTES: u64 = 1024 * 1024;
const AUTH_TIMEOUT: Duration = Duration::from_secs(5);
const COMMAND_TIMEOUT: Duration = Duration::from_secs(10);
const EVENT_BATCH: i64 = 200;
const MAX_TABS: usize = 200;
const BROWSERS: [&str; 2] = ["chrome", "edge"];
/// With no extension connected there is nothing to run, so only poll at this
/// multiple of the tick to let the server expire or fail stale commands.
const IDLE_POLL_EVERY: u64 = 10;

#[derive(Clone, Default)]
pub struct Bridge {
    inner: Arc<Inner>,
}

#[derive(Default)]
struct Inner {
    token: Mutex<String>,
    next_connection: AtomicU64,
    hosts: Mutex<HashMap<String, Host>>,
    snapshots: Mutex<HashMap<String, PendingSnapshot>>,
    waiting: Mutex<HashMap<String, oneshot::Sender<Value>>>,
}

struct Host {
    connection_id: u64,
    tx: mpsc::UnboundedSender<String>,
}

#[derive(Clone)]
struct PendingSnapshot {
    observed_at: String,
    session_uuid: Option<String>,
    tabs: Vec<Value>,
    dirty: bool,
}

struct Connection {
    id: u64,
    tx: mpsc::UnboundedSender<String>,
    browser: Option<String>,
}

impl Bridge {
    pub fn has_host(&self) -> bool {
        !self.inner.hosts.lock().unwrap().is_empty()
    }

    fn register_host(&self, browser: &str, connection_id: u64, tx: mpsc::UnboundedSender<String>) {
        self.inner
            .hosts
            .lock()
            .unwrap()
            .insert(browser.to_string(), Host { connection_id, tx });
    }

    fn unregister_host(&self, browser: &str, connection_id: u64) {
        let mut hosts = self.inner.hosts.lock().unwrap();
        if hosts.get(browser).is_some_and(|host| host.connection_id == connection_id) {
            hosts.remove(browser);
        }
    }

    /// Asks every connected extension to send a fresh tab snapshot, used when the
    /// signed-in student changes so tabs are re-attributed straight away.
    pub fn request_snapshots(&self) {
        self.broadcast(&envelope(&Uuid::new_v4().to_string(), "browser.request_snapshot", json!({})));
    }

    /// Sends one already-framed line to every connected extension.
    pub(crate) fn broadcast(&self, line: &str) {
        for host in self.inner.hosts.lock().unwrap().values() {
            let _ = host.tx.send(line.to_string());
        }
    }

    fn pick_host(&self, browser: Option<&str>) -> Option<mpsc::UnboundedSender<String>> {
        let hosts = self.inner.hosts.lock().unwrap();
        match browser {
            Some(name) => hosts.get(name).map(|host| host.tx.clone()),
            None => BROWSERS.iter().find_map(|name| hosts.get(*name)).map(|host| host.tx.clone()),
        }
    }

    /// Sends one command to the extension and waits for its single response.
    async fn dispatch(&self, browser: Option<&str>, id: &str, kind: &str, payload: Value) -> Result<Value, &'static str> {
        self.dispatch_within(COMMAND_TIMEOUT, browser, id, kind, payload).await
    }

    async fn dispatch_within(
        &self,
        timeout: Duration,
        browser: Option<&str>,
        id: &str,
        kind: &str,
        payload: Value,
    ) -> Result<Value, &'static str> {
        let tx = self.pick_host(browser).ok_or("EXTENSION_UNAVAILABLE")?;
        let (responder, response) = oneshot::channel();
        self.inner.waiting.lock().unwrap().insert(id.to_string(), responder);

        if tx.send(envelope(id, kind, payload)).is_err() {
            self.inner.waiting.lock().unwrap().remove(id);
            return Err("EXTENSION_UNAVAILABLE");
        }

        let outcome = tokio::time::timeout(timeout, response).await;
        self.inner.waiting.lock().unwrap().remove(id);
        match outcome {
            Ok(Ok(value)) => Ok(value),
            Ok(Err(_)) => Err("EXTENSION_UNAVAILABLE"),
            Err(_) => Err("EXTENSION_TIMEOUT"),
        }
    }
}

pub(crate) fn envelope(id: &str, kind: &str, payload: Value) -> String {
    let mut line = json!({
        "version": 1,
        "id": id,
        "type": kind,
        "occurred_at": Utc::now().to_rfc3339(),
        "payload": payload,
    })
    .to_string();
    line.push('\n');
    line
}

pub fn bridge_dir() -> PathBuf {
    if let Some(dir) = std::env::var_os("TOH_KLAS_BRIDGE_DIR") {
        return PathBuf::from(dir);
    }
    std::env::var_os("LOCALAPPDATA")
        .map(PathBuf::from)
        .unwrap_or_else(std::env::temp_dir)
        .join("TOH Klas")
}

fn write_bridge_file(dir: &std::path::Path, port: u16, token: &str) -> io::Result<()> {
    std::fs::create_dir_all(dir)?;
    let contents = json!({ "port": port, "token": token, "pid": std::process::id() }).to_string();
    let temporary = dir.join("bridge.json.tmp");
    std::fs::write(&temporary, contents)?;
    std::fs::rename(temporary, dir.join("bridge.json"))
}

/// Binds an ephemeral loopback port, publishes it (with a per-launch secret) for
/// the native host, and serves connections until the process exits.
pub async fn start(state: AppState) -> io::Result<u16> {
    start_in(state, bridge_dir()).await
}

async fn start_in(state: AppState, dir: PathBuf) -> io::Result<u16> {
    let listener = TcpListener::bind(("127.0.0.1", 0)).await?;
    let port = listener.local_addr()?.port();
    let token = format!("{}{}", Uuid::new_v4().simple(), Uuid::new_v4().simple());
    *state.bridge.inner.token.lock().unwrap() = token.clone();
    write_bridge_file(&dir, port, &token)?;

    tokio::spawn(async move {
        loop {
            let Ok((stream, _)) = listener.accept().await else { continue };
            let connection_id = state.bridge.inner.next_connection.fetch_add(1, Ordering::SeqCst);
            tokio::spawn(handle_connection(state.clone(), stream, connection_id));
        }
    });

    Ok(port)
}

fn constant_time_eq(a: &str, b: &str) -> bool {
    a.len() == b.len() && a.bytes().zip(b.bytes()).fold(0u8, |acc, (x, y)| acc | (x ^ y)) == 0
}

async fn read_line<R: AsyncBufRead + Unpin>(reader: &mut R) -> io::Result<Option<String>> {
    let mut buffer = String::new();
    let read = (&mut *reader).take(MAX_LINE_BYTES + 1).read_line(&mut buffer).await?;
    if read == 0 {
        return Ok(None);
    }
    if read as u64 > MAX_LINE_BYTES {
        return Err(io::Error::new(io::ErrorKind::InvalidData, "line too long"));
    }
    Ok(Some(buffer))
}

async fn handle_connection(state: AppState, stream: TcpStream, connection_id: u64) {
    let (read_half, mut write_half) = stream.into_split();
    let mut reader = BufReader::new(read_half);

    let authenticated = match tokio::time::timeout(AUTH_TIMEOUT, read_line(&mut reader)).await {
        Ok(Ok(Some(line))) => serde_json::from_str::<Value>(&line).is_ok_and(|message| {
            message["type"] == "bridge.auth"
                && message["token"].as_str().is_some_and(|token| {
                    let expected = state.bridge.inner.token.lock().unwrap();
                    !expected.is_empty() && constant_time_eq(token, &expected)
                })
        }),
        _ => false,
    };
    if !authenticated {
        return;
    }

    let (tx, mut rx) = mpsc::unbounded_channel::<String>();
    tokio::spawn(async move {
        while let Some(line) = rx.recv().await {
            if write_half.write_all(line.as_bytes()).await.is_err() {
                break;
            }
        }
    });

    let mut connection = Connection { id: connection_id, tx, browser: None };
    while let Ok(Some(line)) = read_line(&mut reader).await {
        if let Ok(message) = serde_json::from_str::<Value>(&line) {
            handle_message(&state, &mut connection, message);
        }
    }

    if let Some(browser) = &connection.browser {
        state.bridge.unregister_host(browser, connection.id);
    }
}

fn valid_browser(value: &Value) -> Option<String> {
    value.as_str().filter(|name| BROWSERS.contains(name)).map(str::to_string)
}

fn truncate(value: &Value, max_chars: usize) -> Value {
    match value.as_str() {
        Some(text) => Value::String(text.chars().take(max_chars).collect()),
        None => Value::Null,
    }
}

/// Normalizes to the same RFC 3339 shape stored for sessions so lexical
/// comparison of instants is safe.
fn normalize_instant(value: &Value) -> Option<String> {
    let parsed = DateTime::parse_from_rfc3339(value.as_str()?).ok()?;
    Some(parsed.with_timezone(&Utc).to_rfc3339())
}

fn current_session(state: &AppState) -> Option<String> {
    let conn = state.db.lock().unwrap();
    let config = config_repo::get_config(&conn).ok()??;
    session_repo::find_open_session(&conn, config.computer_id).ok()?.map(|session| session.session_uuid)
}

fn session_at(state: &AppState, instant: &str) -> Option<String> {
    let conn = state.db.lock().unwrap();
    let config = config_repo::get_config(&conn).ok()??;
    session_repo::session_uuid_at(&conn, config.computer_id, instant).ok()?
}

fn handle_message(state: &AppState, connection: &mut Connection, message: Value) {
    let payload = &message["payload"];

    match message["type"].as_str().unwrap_or_default() {
        "browser.hello" => {
            if let Some(browser) = valid_browser(&payload["browser"]) {
                state.bridge.register_host(&browser, connection.id, connection.tx.clone());
                connection.browser = Some(browser);
                // A browser that just started gets the current rules straight away.
                if let Some(policy) = crate::policy_sync::cached(state) {
                    let _ = connection.tx.send(crate::policy_sync::message(&policy));
                }
            }
        }
        "browser.snapshot" => {
            let browser = connection.browser.clone().or_else(|| valid_browser(&payload["browser"]));
            if let Some(browser) = browser {
                store_snapshot(state, &browser, payload);
            }
        }
        "browser.activity" => {
            let browser = connection.browser.clone().or_else(|| valid_browser(&payload["browser"]));
            if let Some(browser) = browser {
                store_events(state, &browser, payload);
            }
        }
        "response" => {
            if let Some(id) = message["id"].as_str() {
                if let Some(responder) = state.bridge.inner.waiting.lock().unwrap().remove(id) {
                    let _ = responder.send(payload.clone());
                }
            }
        }
        _ => {}
    }
}

fn store_snapshot(state: &AppState, browser: &str, payload: &Value) {
    let (Some(observed_at), Some(tabs)) = (normalize_instant(&payload["observed_at"]), payload["tabs"].as_array()) else {
        return;
    };

    let tabs: Vec<Value> = tabs
        .iter()
        .take(MAX_TABS)
        .filter_map(|tab| {
            Some(json!({
                "tab_id": tab["tab_id"].as_u64()?,
                "window_id": tab["window_id"].as_i64(),
                "url": truncate(&tab["url"], 2048),
                "title": truncate(&tab["title"], 500),
                "active": tab["active"].as_bool().unwrap_or(false),
            }))
        })
        .collect();

    state.bridge.inner.snapshots.lock().unwrap().insert(
        browser.to_string(),
        PendingSnapshot { observed_at, session_uuid: current_session(state), tabs, dirty: true },
    );
}

fn store_events(state: &AppState, browser: &str, payload: &Value) {
    let Some(events) = payload["events"].as_array() else { return };

    for event in events.iter().take(EVENT_BATCH as usize) {
        let uuid = event["uuid"].as_str().filter(|value| Uuid::parse_str(value).is_ok());
        let kind = event["type"].as_str().filter(|value| matches!(*value, "navigated" | "activated" | "blocked"));
        let (Some(uuid), Some(kind)) = (uuid, kind) else { continue };

        let occurred_at = normalize_instant(&event["occurred_at"]).unwrap_or_else(|| Utc::now().to_rfc3339());
        let session = session_at(state, &occurred_at).or_else(|| current_session(state));
        let url = truncate(&event["url"], 2048);
        let title = truncate(&event["title"], 500);

        let conn = state.db.lock().unwrap();
        let _ = browser_repo::insert_event(
            &conn,
            &browser_repo::NewEvent {
                uuid,
                browser,
                event_type: kind,
                url: url.as_str(),
                title: title.as_str(),
                session_uuid: session.as_deref(),
                occurred_at: &occurred_at,
            },
        );
    }
}

/// One scheduling step: push queued browser data, then check for commands.
pub async fn tick(state: &AppState, tick_number: u64) {
    // Focus sessions end on this computer's clock even when the network is down.
    crate::policy_sync::enforce_local_expiry(state);

    let (base_url, token) = {
        let conn = state.db.lock().unwrap();
        (config_repo::get_api_base_url(&conn).ok().flatten(), crate::credential_store::get_token())
    };
    let (Some(base_url), Some(token)) = (base_url, token) else { return };

    flush_browser_data(state, &base_url, &token).await;

    let active = state.bridge.has_host() && current_session(state).is_some();
    if active || tick_number % IDLE_POLL_EVERY == 0 {
        crate::policy_sync::sync(state, &base_url, &token).await;
        poll_commands(state, &base_url, &token).await;
    }
}

/// A 4xx other than auth/rate-limit means the payload can never be accepted, so
/// retrying it forever would wedge the queue.
fn is_permanent_rejection(status: Option<u16>) -> bool {
    matches!(status, Some(code) if (400..500).contains(&code) && !matches!(code, 401 | 403 | 408 | 429))
}

async fn flush_browser_data(state: &AppState, base_url: &str, token: &str) {
    let mut browsers: Vec<String> = state
        .bridge
        .inner
        .snapshots
        .lock()
        .unwrap()
        .iter()
        .filter(|(_, snapshot)| snapshot.dirty)
        .map(|(browser, _)| browser.clone())
        .collect();
    let queued = {
        let conn = state.db.lock().unwrap();
        browser_repo::browsers_with_pending_events(&conn).unwrap_or_default()
    };
    for browser in queued {
        if !browsers.contains(&browser) {
            browsers.push(browser);
        }
    }

    for browser in browsers {
        let snapshot = state.bridge.inner.snapshots.lock().unwrap().get(&browser).filter(|s| s.dirty).cloned();
        let events = {
            let conn = state.db.lock().unwrap();
            browser_repo::pending_events(&conn, &browser, EVENT_BATCH).unwrap_or_default()
        };

        let mut body = json!({ "browser": browser, "events": events });
        if let Some(snapshot) = &snapshot {
            body["snapshot"] = json!({
                "observed_at": snapshot.observed_at,
                "session_uuid": snapshot.session_uuid,
                "tabs": snapshot.tabs,
            });
        }

        let outcome = crate::api_client::post_browser_events(&state.http, base_url, token, &body).await;
        let accepted = outcome.is_ok() || is_permanent_rejection(outcome.err().flatten());
        if !accepted {
            continue;
        }

        let sent: Vec<String> = events.iter().filter_map(|e| e["uuid"].as_str().map(str::to_string)).collect();
        let conn = state.db.lock().unwrap();
        let _ = browser_repo::delete_events(&conn, &sent);
        drop(conn);

        if let Some(sent_snapshot) = snapshot {
            if let Some(stored) = state.bridge.inner.snapshots.lock().unwrap().get_mut(&browser) {
                if stored.observed_at == sent_snapshot.observed_at {
                    stored.dirty = false;
                }
            }
        }
    }
}

async fn poll_commands(state: &AppState, base_url: &str, token: &str) {
    let Ok(commands) = crate::api_client::fetch_commands(&state.http, base_url, token).await else { return };

    for command in commands {
        let (state, base_url, token) = (state.clone(), base_url.to_string(), token.to_string());
        tokio::spawn(async move { run_command(&state, &base_url, &token, command).await });
    }
}

async fn run_command(state: &AppState, base_url: &str, token: &str, command: Value) {
    let Some(id) = command["id"].as_str() else { return };
    let (status, result) = execute(state, &command, id).await;
    let _ = crate::api_client::post_command_result(&state.http, base_url, token, id, status, result).await;
}

async fn execute(state: &AppState, command: &Value, id: &str) -> (&'static str, Value) {
    let failed = |code: &str| ("failed", json!({ "error": code }));

    let expired = command["expires_at"]
        .as_str()
        .and_then(|value| DateTime::parse_from_rfc3339(value).ok())
        .is_none_or(|deadline| deadline < Utc::now());
    if expired {
        return failed("EXPIRED");
    }

    // The server checks this too; repeating it here means a sign-out that
    // raced the poll can never run one student's command against another's session.
    let current = current_session(state);
    if current.is_none() || command["student_session_id"].as_str() != current.as_deref() {
        return failed("SESSION_MISMATCH");
    }

    let kind = command["type"].as_str().unwrap_or_default();
    let payload = command["payload"].clone();
    let browser = payload["browser"].as_str().map(str::to_string);

    match state.bridge.dispatch(browser.as_deref(), id, kind, payload).await {
        Ok(response) => match response["status"].as_str() {
            Some("completed") => ("completed", response["result"].clone()),
            Some("failed") => ("failed", response["result"].clone()),
            _ => failed("BAD_EXTENSION_RESPONSE"),
        },
        Err(code) => failed(code),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::models::StudentSummary;
    use tokio::io::AsyncWriteExt;

    struct Harness {
        state: AppState,
        port: u16,
        token: String,
    }

    async fn harness() -> Harness {
        let dir = std::env::temp_dir().join(format!("toh-klas-bridge-test-{}", Uuid::new_v4()));

        let conn = crate::db::open(std::path::Path::new(":memory:")).unwrap();
        config_repo::save_provisioning(&conn, "http://localhost", "", 1, 7, "PC", Some("dev"), Some(1), 1).unwrap();
        let state = AppState::new(conn);
        let port = start_in(state.clone(), dir).await.unwrap();
        let token = state.bridge.inner.token.lock().unwrap().clone();
        Harness { state, port, token }
    }

    fn sign_in(state: &AppState, admission: &str) -> String {
        let conn = state.db.lock().unwrap();
        let student = StudentSummary { id: 1, admission_number: admission.into(), full_name: "Student".into() };
        session_repo::insert_login(&conn, 1, 7, &student).unwrap().session_uuid
    }

    async fn connect(harness: &Harness) -> (BufReader<tokio::net::tcp::OwnedReadHalf>, tokio::net::tcp::OwnedWriteHalf) {
        let stream = TcpStream::connect(("127.0.0.1", harness.port)).await.unwrap();
        let (read, mut write) = stream.into_split();
        write
            .write_all(format!("{}\n", json!({ "type": "bridge.auth", "token": harness.token })).as_bytes())
            .await
            .unwrap();
        (BufReader::new(read), write)
    }

    async fn send(write: &mut tokio::net::tcp::OwnedWriteHalf, kind: &str, payload: Value) {
        let line = json!({ "version": 1, "id": Uuid::new_v4().to_string(), "type": kind, "payload": payload });
        write.write_all(format!("{line}\n").as_bytes()).await.unwrap();
    }

    async fn eventually(mut check: impl FnMut() -> bool) {
        for _ in 0..100 {
            if check() {
                return;
            }
            tokio::time::sleep(Duration::from_millis(20)).await;
        }
        panic!("condition was never met");
    }

    #[tokio::test]
    async fn a_connection_with_the_wrong_secret_is_dropped_and_never_registered() {
        let harness = harness().await;
        let stream = TcpStream::connect(("127.0.0.1", harness.port)).await.unwrap();
        let (read, mut write) = stream.into_split();
        write.write_all(b"{\"type\":\"bridge.auth\",\"token\":\"nope\"}\n").await.unwrap();
        let _ = write.write_all(format!("{}\n", json!({"type": "browser.hello", "payload": {"browser": "chrome"}})).as_bytes()).await;

        let mut reader = BufReader::new(read);
        assert!(read_line(&mut reader).await.unwrap_or(None).is_none());
        assert!(!harness.state.bridge.has_host());
    }

    #[tokio::test]
    async fn an_http_request_aimed_at_the_port_is_rejected() {
        let harness = harness().await;
        let mut stream = TcpStream::connect(("127.0.0.1", harness.port)).await.unwrap();
        stream.write_all(b"POST / HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n").await.unwrap();

        let mut reader = BufReader::new(stream);
        assert!(read_line(&mut reader).await.unwrap_or(None).is_none());
    }

    #[tokio::test]
    async fn snapshots_are_sanitized_and_tagged_with_the_open_session() {
        let harness = harness().await;
        let session = sign_in(&harness.state, "1001");
        let (_reader, mut write) = connect(&harness).await;

        send(&mut write, "browser.hello", json!({ "browser": "edge" })).await;
        send(&mut write, "browser.snapshot", json!({
            "observed_at": "2026-09-22T08:00:00.000Z",
            "tabs": [
                { "tab_id": 4, "window_id": 1, "url": "https://a.example/", "title": "A", "active": true },
                { "tab_id": "bad", "url": "x" },
                { "tab_id": 5, "window_id": null, "url": "x".repeat(5000), "title": 7, "active": "yes" }
            ]
        })).await;

        let inner = harness.state.bridge.inner.clone();
        eventually(|| inner.snapshots.lock().unwrap().contains_key("edge")).await;
        let snapshot = inner.snapshots.lock().unwrap().get("edge").cloned().unwrap();
        assert_eq!(snapshot.session_uuid.as_deref(), Some(session.as_str()));
        assert_eq!(snapshot.tabs.len(), 2);
        assert_eq!(snapshot.tabs[1]["url"].as_str().unwrap().len(), 2048);
        assert!(snapshot.tabs[1]["title"].is_null());
        assert_eq!(snapshot.tabs[1]["active"], false);
        assert!(snapshot.dirty);
    }

    #[tokio::test]
    async fn events_are_credited_to_the_session_open_when_they_happened() {
        let harness = harness().await;
        let first = sign_in(&harness.state, "1001");
        let during_first = Utc::now().to_rfc3339();
        tokio::time::sleep(Duration::from_millis(30)).await;
        {
            let conn = harness.state.db.lock().unwrap();
            session_repo::close_logout(&conn, &first).unwrap();
        }
        tokio::time::sleep(Duration::from_millis(30)).await;
        let second = sign_in(&harness.state, "1002");
        let (_reader, mut write) = connect(&harness).await;

        let old_event = Uuid::new_v4().to_string();
        let new_event = Uuid::new_v4().to_string();
        send(&mut write, "browser.activity", json!({ "browser": "chrome", "events": [
            { "uuid": old_event, "type": "navigated", "url": "https://old.example/", "title": "Old", "occurred_at": during_first },
            { "uuid": new_event, "type": "activated", "url": "https://new.example/", "title": "New", "occurred_at": Utc::now().to_rfc3339() },
            { "uuid": "not-a-uuid", "type": "navigated", "occurred_at": Utc::now().to_rfc3339() },
            { "uuid": Uuid::new_v4().to_string(), "type": "explode", "occurred_at": Utc::now().to_rfc3339() }
        ] })).await;

        let state = harness.state.clone();
        eventually(|| browser_repo::pending_events(&state.db.lock().unwrap(), "chrome", 10).unwrap().len() == 2).await;
        let events = browser_repo::pending_events(&harness.state.db.lock().unwrap(), "chrome", 10).unwrap();
        assert_eq!(events[0]["session_uuid"], first.as_str());
        assert_eq!(events[1]["session_uuid"], second.as_str());
    }

    #[tokio::test]
    async fn a_command_round_trips_through_the_connected_extension() {
        let harness = harness().await;
        let (mut reader, mut write) = connect(&harness).await;
        send(&mut write, "browser.hello", json!({ "browser": "chrome" })).await;
        let bridge = harness.state.bridge.clone();
        eventually(|| bridge.has_host()).await;

        let command_id = Uuid::new_v4().to_string();
        let extension = tokio::spawn(async move {
            let line = read_line(&mut reader).await.unwrap().unwrap();
            let request: Value = serde_json::from_str(&line).unwrap();
            assert_eq!(request["type"], "browser.open_url");
            assert_eq!(request["payload"]["url"], "https://example.com");
            let reply = json!({ "version": 1, "id": request["id"], "type": "response", "payload": { "status": "completed", "result": { "tab_id": 9 } } });
            write.write_all(format!("{reply}\n").as_bytes()).await.unwrap();
            write
        });

        let response = bridge.dispatch(Some("chrome"), &command_id, "browser.open_url", json!({ "url": "https://example.com" })).await.unwrap();
        assert_eq!(response["status"], "completed");
        assert_eq!(response["result"]["tab_id"], 9);
        let _write = extension.await.unwrap();
    }

    #[tokio::test]
    async fn dispatch_reports_an_unavailable_extension_and_a_silent_one() {
        let harness = harness().await;
        assert_eq!(harness.state.bridge.dispatch(None, "x", "browser.open_url", json!({})).await, Err("EXTENSION_UNAVAILABLE"));

        let (_reader, mut write) = connect(&harness).await;
        send(&mut write, "browser.hello", json!({ "browser": "edge" })).await;
        let bridge = harness.state.bridge.clone();
        eventually(|| bridge.has_host()).await;

        let outcome = bridge.dispatch_within(Duration::from_millis(100), Some("edge"), "y", "browser.open_url", json!({})).await;
        assert_eq!(outcome, Err("EXTENSION_TIMEOUT"));
    }

    #[tokio::test]
    async fn execute_refuses_stale_expired_and_mismatched_commands() {
        let harness = harness().await;
        let session = sign_in(&harness.state, "1001");
        let future = (Utc::now() + chrono::Duration::seconds(30)).to_rfc3339();
        let past = (Utc::now() - chrono::Duration::seconds(30)).to_rfc3339();

        let expired = json!({ "expires_at": past, "student_session_id": session, "type": "browser.open_url", "payload": {} });
        assert_eq!(execute(&harness.state, &expired, "a").await.1["error"], "EXPIRED");

        let wrong = json!({ "expires_at": future, "student_session_id": Uuid::new_v4().to_string(), "type": "browser.open_url", "payload": {} });
        assert_eq!(execute(&harness.state, &wrong, "b").await.1["error"], "SESSION_MISMATCH");

        let no_extension = json!({ "expires_at": future, "student_session_id": session, "type": "browser.open_url", "payload": {} });
        assert_eq!(execute(&harness.state, &no_extension, "c").await.1["error"], "EXTENSION_UNAVAILABLE");
    }

    #[tokio::test]
    async fn a_browser_that_connects_is_given_the_cached_policy_immediately() {
        let harness = harness().await;
        let policy = crate::policy_sync::CachedPolicy { version: "v9".into(), block: vec!["games.example".into()], focus: None };
        crate::db::policy_repo::save(&harness.state.db.lock().unwrap(), &policy).unwrap();

        let (mut reader, mut write) = connect(&harness).await;
        send(&mut write, "browser.hello", json!({ "browser": "chrome" })).await;

        let line = tokio::time::timeout(Duration::from_secs(5), read_line(&mut reader)).await.unwrap().unwrap().unwrap();
        let message: Value = serde_json::from_str(&line).unwrap();
        assert_eq!(message["type"], "policy.update");
        assert_eq!(message["payload"]["version"], "v9");
        assert_eq!(message["payload"]["block"], json!(["games.example"]));
    }

    #[tokio::test]
    async fn a_policy_change_is_pushed_to_every_connected_extension() {
        let harness = harness().await;
        let (mut chrome, mut chrome_write) = connect(&harness).await;
        let (mut edge, mut edge_write) = connect(&harness).await;
        send(&mut chrome_write, "browser.hello", json!({ "browser": "chrome" })).await;
        send(&mut edge_write, "browser.hello", json!({ "browser": "edge" })).await;
        let bridge = harness.state.bridge.clone();
        eventually(|| bridge.inner.hosts.lock().unwrap().len() == 2).await;

        let policy = crate::policy_sync::CachedPolicy { version: "v2".into(), block: vec!["a.example".into()], focus: None };
        bridge.broadcast(&crate::policy_sync::message(&policy));

        for reader in [&mut chrome, &mut edge] {
            let line = tokio::time::timeout(Duration::from_secs(5), read_line(reader)).await.unwrap().unwrap().unwrap();
            assert_eq!(serde_json::from_str::<Value>(&line).unwrap()["payload"]["version"], "v2");
        }
    }

    #[tokio::test]
    async fn blocked_attempts_are_queued_like_any_other_browsing_event() {
        let harness = harness().await;
        sign_in(&harness.state, "1001");
        let (_reader, mut write) = connect(&harness).await;

        send(&mut write, "browser.activity", json!({ "browser": "chrome", "events": [
            { "uuid": Uuid::new_v4().to_string(), "type": "blocked", "url": "https://games.example/", "title": "Blocked (focus)", "occurred_at": Utc::now().to_rfc3339() }
        ] })).await;

        let state = harness.state.clone();
        eventually(|| browser_repo::pending_events(&state.db.lock().unwrap(), "chrome", 10).unwrap().len() == 1).await;
        let events = browser_repo::pending_events(&harness.state.db.lock().unwrap(), "chrome", 10).unwrap();
        assert_eq!(events[0]["type"], "blocked");
        assert!(events[0]["session_uuid"].is_string(), "attributed to the signed-in student");
    }

    #[test]
    fn permanent_rejections_are_distinguished_from_retryable_failures() {
        assert!(is_permanent_rejection(Some(422)));
        assert!(is_permanent_rejection(Some(400)));
        assert!(!is_permanent_rejection(Some(401)));
        assert!(!is_permanent_rejection(Some(429)));
        assert!(!is_permanent_rejection(Some(500)));
        assert!(!is_permanent_rejection(None));
    }
}

#[cfg(test)]
mod full_stack;
