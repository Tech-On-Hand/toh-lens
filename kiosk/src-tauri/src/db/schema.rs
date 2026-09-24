use rusqlite::Connection;

const MIGRATION_V1: &str = r#"
CREATE TABLE app_config (
    key   TEXT PRIMARY KEY,
    value TEXT
);

CREATE TABLE students (
    id               INTEGER PRIMARY KEY,
    school_id        INTEGER NOT NULL,
    class_id         INTEGER,
    admission_number TEXT NOT NULL,
    full_name        TEXT NOT NULL,
    is_active        INTEGER NOT NULL DEFAULT 1,
    UNIQUE (school_id, admission_number)
);

CREATE TABLE login_sessions (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    session_uuid      TEXT NOT NULL UNIQUE,
    school_id         INTEGER NOT NULL,
    computer_id       INTEGER NOT NULL,
    student_id        INTEGER,
    admission_number  TEXT NOT NULL,
    full_name         TEXT NOT NULL,
    login_time        TEXT NOT NULL,
    logout_time       TEXT,
    synced            INTEGER NOT NULL DEFAULT 0,
    synced_at         TEXT,
    created_at        TEXT NOT NULL,
    updated_at        TEXT NOT NULL
);

CREATE INDEX idx_login_sessions_unsynced ON login_sessions (synced);
CREATE INDEX idx_login_sessions_open ON login_sessions (computer_id, logout_time);
"#;

/// Browser navigation events wait here until the backend acknowledges them,
/// so a network outage never loses or mis-attributes student activity.
const MIGRATION_V2: &str = r#"
CREATE TABLE browser_events (
    uuid         TEXT PRIMARY KEY,
    browser      TEXT NOT NULL,
    event_type   TEXT NOT NULL,
    url          TEXT,
    title        TEXT,
    session_uuid TEXT,
    occurred_at  TEXT NOT NULL,
    created_at   TEXT NOT NULL
);
"#;

/// The last policy the backend sent, so blocking and focus keep working (and
/// survive a restart) with no connection.
const MIGRATION_V3: &str = r#"
CREATE TABLE policy_cache (
    id         INTEGER PRIMARY KEY CHECK (id = 1),
    version    TEXT NOT NULL,
    payload    TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
"#;

/// What a student did that the server has not been told about yet (chat
/// messages, raised hands, dismissed announcements), so an outage or a restart
/// never loses it. See `outbox.rs`.
const MIGRATION_V4: &str = r#"
CREATE TABLE outbox (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    kind       TEXT NOT NULL,
    key        TEXT,
    payload    TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX idx_outbox_kind_key ON outbox (kind, key);
"#;

/// Which desktop application the signed-in student had in front, as intervals
/// waiting to be uploaded (process name only, never a window title).
/// `synced_ended_at` is the end the server last confirmed, so an interval that
/// was still open when it was sent is sent again once it has grown.
const MIGRATION_V5: &str = r#"
CREATE TABLE app_usage (
    uuid            TEXT PRIMARY KEY,
    session_uuid    TEXT NOT NULL,
    process         TEXT NOT NULL,
    is_idle         INTEGER NOT NULL,
    started_at      TEXT NOT NULL,
    ended_at        TEXT NOT NULL,
    synced_ended_at TEXT
);

CREATE INDEX idx_app_usage_session ON app_usage (session_uuid);
"#;

/// Step-wise migrations keyed by `PRAGMA user_version`. Add new steps by
/// appending to this slice — never edit a step that has already shipped.
const MIGRATIONS: &[&str] = &[MIGRATION_V1, MIGRATION_V2, MIGRATION_V3, MIGRATION_V4, MIGRATION_V5];

pub fn run_migrations(conn: &Connection) -> rusqlite::Result<()> {
    let current_version: i64 = conn.query_row("PRAGMA user_version", [], |row| row.get(0))?;

    for (index, migration) in MIGRATIONS.iter().enumerate() {
        let step_version = (index + 1) as i64;
        if step_version <= current_version {
            continue;
        }

        conn.execute_batch(migration)?;
        conn.execute_batch(&format!("PRAGMA user_version = {step_version}"))?;
    }

    Ok(())
}
