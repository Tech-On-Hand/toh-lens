//! Things a student did that the server has not been told about yet (a chat
//! message, a raised hand, a dismissed announcement), kept on disk so an outage or
//! a restart never loses them. `outbox.rs` drains this in order.

use chrono::Utc;
use rusqlite::{Connection, OptionalExtension};
use serde_json::Value;

pub const CHAT_MESSAGE: &str = "chat_message";
pub const HELP_REQUEST: &str = "help_request";
pub const HELP_CANCEL: &str = "help_cancel";
pub const ANNOUNCEMENT_READ: &str = "announcement_read";
pub const CHAT_READ: &str = "chat_read";

#[derive(Debug, Clone)]
pub struct Entry {
    pub id: i64,
    pub kind: String,
    pub key: Option<String>,
    pub payload: Value,
    pub created_at: String,
}

pub fn enqueue(conn: &Connection, kind: &str, key: Option<&str>, payload: &Value) -> rusqlite::Result<()> {
    conn.execute(
        "INSERT INTO outbox (kind, key, payload, created_at) VALUES (?1, ?2, ?3, ?4)",
        (kind, key, payload.to_string(), Utc::now().to_rfc3339()),
    )?;
    Ok(())
}

/// Oldest first, so what the student did is delivered in the order they did it.
pub fn pending(conn: &Connection, limit: i64) -> rusqlite::Result<Vec<Entry>> {
    let mut statement = conn.prepare("SELECT id, kind, key, payload, created_at FROM outbox ORDER BY id LIMIT ?1")?;
    let rows = statement.query_map([limit], |row| {
        let payload: String = row.get(3)?;
        Ok(Entry {
            id: row.get(0)?,
            kind: row.get(1)?,
            key: row.get(2)?,
            payload: serde_json::from_str(&payload).unwrap_or(Value::Null),
            created_at: row.get(4)?,
        })
    })?;
    rows.collect()
}

pub fn delete(conn: &Connection, id: i64) -> rusqlite::Result<()> {
    conn.execute("DELETE FROM outbox WHERE id = ?1", [id])?;
    Ok(())
}

/// Removes an entry that has not been sent yet. `true` if there was one.
pub fn remove(conn: &Connection, kind: &str, key: &str) -> rusqlite::Result<bool> {
    Ok(conn.execute("DELETE FROM outbox WHERE kind = ?1 AND key = ?2", (kind, key))? > 0)
}

pub fn has_kind(conn: &Connection, kind: &str) -> rusqlite::Result<bool> {
    let found: Option<i64> = conn.query_row("SELECT 1 FROM outbox WHERE kind = ?1 LIMIT 1", [kind], |row| row.get(0)).optional()?;
    Ok(found.is_some())
}

#[cfg(test)]
pub fn has(conn: &Connection, kind: &str, key: &str) -> rusqlite::Result<bool> {
    let found: Option<i64> = conn
        .query_row("SELECT 1 FROM outbox WHERE kind = ?1 AND key = ?2 LIMIT 1", (kind, key), |row| row.get(0))
        .optional()?;
    Ok(found.is_some())
}

pub fn of_kind(conn: &Connection, kind: &str) -> rusqlite::Result<Vec<Entry>> {
    Ok(pending(conn, 500)?.into_iter().filter(|entry| entry.kind == kind).collect())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn database() -> Connection {
        let conn = Connection::open_in_memory().unwrap();
        crate::db::schema::run_migrations(&conn).unwrap();
        conn
    }

    #[test]
    fn entries_come_back_oldest_first() {
        let conn = database();
        enqueue(&conn, CHAT_MESSAGE, Some("a"), &json!({"body": "one"})).unwrap();
        enqueue(&conn, CHAT_MESSAGE, Some("b"), &json!({"body": "two"})).unwrap();

        let entries = pending(&conn, 10).unwrap();
        assert_eq!(entries.iter().map(|e| e.key.clone().unwrap()).collect::<Vec<_>>(), ["a", "b"]);
        assert_eq!(entries[0].payload["body"], "one");
    }

    #[test]
    fn a_delivered_entry_is_deleted_and_survives_nothing_else() {
        let conn = database();
        enqueue(&conn, CHAT_MESSAGE, Some("a"), &json!({})).unwrap();
        enqueue(&conn, CHAT_MESSAGE, Some("b"), &json!({})).unwrap();

        delete(&conn, pending(&conn, 10).unwrap()[0].id).unwrap();

        assert!(!has(&conn, CHAT_MESSAGE, "a").unwrap());
        assert!(has(&conn, CHAT_MESSAGE, "b").unwrap());
    }

    #[test]
    fn removing_an_unsent_entry_reports_whether_it_was_there() {
        let conn = database();
        enqueue(&conn, HELP_REQUEST, Some("req"), &json!({})).unwrap();

        assert!(remove(&conn, HELP_REQUEST, "req").unwrap());
        assert!(!remove(&conn, HELP_REQUEST, "req").unwrap());
    }

    #[test]
    fn kinds_are_told_apart() {
        let conn = database();
        enqueue(&conn, CHAT_READ, None, &json!({})).unwrap();

        assert!(has_kind(&conn, CHAT_READ).unwrap());
        assert!(!has_kind(&conn, HELP_CANCEL).unwrap());
        assert_eq!(of_kind(&conn, CHAT_READ).unwrap().len(), 1);
    }
}
