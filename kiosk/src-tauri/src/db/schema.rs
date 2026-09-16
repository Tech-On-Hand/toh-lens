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

/// Step-wise migrations keyed by `PRAGMA user_version`. Add new steps by
/// appending to this slice — never edit a step that has already shipped.
const MIGRATIONS: &[&str] = &[MIGRATION_V1];

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
