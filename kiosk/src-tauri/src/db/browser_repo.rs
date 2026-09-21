use chrono::Utc;
use rusqlite::Connection;
use serde_json::{json, Value};

/// Bound on offline backlog; oldest rows are dropped first so a machine that
/// stays offline for weeks cannot fill the disk.
const MAX_QUEUED_EVENTS: i64 = 5000;
const PRUNE_BATCH: i64 = 500;

pub struct NewEvent<'a> {
    pub uuid: &'a str,
    pub browser: &'a str,
    pub event_type: &'a str,
    pub url: Option<&'a str>,
    pub title: Option<&'a str>,
    pub session_uuid: Option<&'a str>,
    pub occurred_at: &'a str,
}

/// Idempotent on the event uuid, mirroring the server's dedupe.
pub fn insert_event(conn: &Connection, event: &NewEvent) -> rusqlite::Result<()> {
    conn.execute(
        "INSERT OR IGNORE INTO browser_events
            (uuid, browser, event_type, url, title, session_uuid, occurred_at, created_at)
         VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8)",
        (
            event.uuid,
            event.browser,
            event.event_type,
            event.url,
            event.title,
            event.session_uuid,
            event.occurred_at,
            Utc::now().to_rfc3339(),
        ),
    )?;

    let count: i64 = conn.query_row("SELECT COUNT(*) FROM browser_events", [], |row| row.get(0))?;
    if count > MAX_QUEUED_EVENTS {
        conn.execute(
            "DELETE FROM browser_events WHERE rowid IN (SELECT rowid FROM browser_events ORDER BY rowid ASC LIMIT ?1)",
            [PRUNE_BATCH],
        )?;
    }
    Ok(())
}

/// Oldest queued events for one browser, shaped as the API's `events` entries.
pub fn pending_events(conn: &Connection, browser: &str, limit: i64) -> rusqlite::Result<Vec<Value>> {
    let mut stmt = conn.prepare(
        "SELECT uuid, event_type, url, title, session_uuid, occurred_at
         FROM browser_events WHERE browser = ?1 ORDER BY rowid ASC LIMIT ?2",
    )?;
    let rows = stmt.query_map((browser, limit), |row| {
        Ok(json!({
            "uuid": row.get::<_, String>(0)?,
            "type": row.get::<_, String>(1)?,
            "url": row.get::<_, Option<String>>(2)?,
            "title": row.get::<_, Option<String>>(3)?,
            "session_uuid": row.get::<_, Option<String>>(4)?,
            "occurred_at": row.get::<_, String>(5)?,
        }))
    })?;
    rows.collect()
}

pub fn browsers_with_pending_events(conn: &Connection) -> rusqlite::Result<Vec<String>> {
    let mut stmt = conn.prepare("SELECT DISTINCT browser FROM browser_events")?;
    let rows = stmt.query_map([], |row| row.get(0))?;
    rows.collect()
}

pub fn delete_events(conn: &Connection, uuids: &[String]) -> rusqlite::Result<()> {
    let mut stmt = conn.prepare("DELETE FROM browser_events WHERE uuid = ?1")?;
    for uuid in uuids {
        stmt.execute([uuid])?;
    }
    Ok(())
}
