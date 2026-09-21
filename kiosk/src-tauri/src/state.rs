use rusqlite::Connection;
use std::process::Child;
use std::sync::{Arc, Mutex};

#[derive(Clone)]
pub struct AppState {
    pub db: Arc<Mutex<Connection>>,
    pub http: reqwest::Client,
    /// Tracks the `explorer.exe` process handed off to on login (release
    /// builds only), so logout can reclaim the desktop by killing exactly
    /// that process. See `shell_handoff.rs` — unread in debug builds since
    /// the handoff is a no-op there.
    #[allow(dead_code)]
    pub desktop_child: Arc<Mutex<Option<Child>>>,
    pub bridge: crate::browser_bridge::Bridge,
}

impl AppState {
    pub fn new(db: Connection) -> Self {
        let http = reqwest::Client::builder()
            .timeout(std::time::Duration::from_secs(10))
            .build()
            .expect("failed to build HTTP client");

        Self {
            db: Arc::new(Mutex::new(db)),
            http,
            desktop_child: Arc::new(Mutex::new(None)),
            bridge: crate::browser_bridge::Bridge::default(),
        }
    }
}
