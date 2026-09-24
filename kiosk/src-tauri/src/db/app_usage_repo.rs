//! Which desktop application a student had in front, kept as intervals and queued
//! until the server has them. Only process names are stored (never window titles).
//! `app_activity.rs` feeds this once per tick and uploads what is waiting.

use chrono::{DateTime, Duration, Utc};
use rusqlite::{Connection, OptionalExtension};
use serde_json::{json, Value};
use uuid::Uuid;

/// Each sample stands for one tick of foreground time.
pub const SAMPLE_SPAN: Duration = Duration::seconds(3);
/// A sample this long after the last one ended is a new interval rather than a
/// continuation (the machine slept, the agent was down, the student was signed out).
const MAX_GAP: Duration = Duration::seconds(8);
/// Bound on the backlog a machine that stays offline can build up.
const MAX_ROWS: i64 = 20_000;
const PRUNE_BATCH: i64 = 1_000;

pub struct Sample<'a> {
    pub session_uuid: &'a str,
    pub process: &'a str,
    pub is_idle: bool,
    pub at: DateTime<Utc>,
}

/// Adds one observation: it extends the session's latest interval if it is the same
/// application in the same state and follows on closely, otherwise it starts a new one.
pub fn record(conn: &Connection, sample: &Sample) -> rusqlite::Result<()> {
    let latest: Option<(String, String, bool, String)> = conn
        .query_row(
            "SELECT uuid, process, is_idle, ended_at FROM app_usage WHERE session_uuid = ?1 ORDER BY rowid DESC LIMIT 1",
            [sample.session_uuid],
            |row| Ok((row.get(0)?, row.get(1)?, row.get(2)?, row.get(3)?)),
        )
        .optional()?;

    let ends_at = (sample.at + SAMPLE_SPAN).to_rfc3339();

    if let Some((uuid, process, is_idle, ended_at)) = latest {
        let continues = process == sample.process
            && is_idle == sample.is_idle
            && DateTime::parse_from_rfc3339(&ended_at).map_or(false, |end| sample.at.signed_duration_since(end.with_timezone(&Utc)) <= MAX_GAP);
        if continues {
            conn.execute("UPDATE app_usage SET ended_at = ?1 WHERE uuid = ?2", (&ends_at, &uuid))?;
            return Ok(());
        }
    }

    conn.execute(
        "INSERT INTO app_usage (uuid, session_uuid, process, is_idle, started_at, ended_at, synced_ended_at)
         VALUES (?1, ?2, ?3, ?4, ?5, ?6, NULL)",
        (Uuid::new_v4().to_string(), sample.session_uuid, sample.process, sample.is_idle, sample.at.to_rfc3339(), &ends_at),
    )?;

    let count: i64 = conn.query_row("SELECT COUNT(*) FROM app_usage", [], |row| row.get(0))?;
    if count > MAX_ROWS {
        conn.execute("DELETE FROM app_usage WHERE rowid IN (SELECT rowid FROM app_usage ORDER BY rowid ASC LIMIT ?1)", [PRUNE_BATCH])?;
    }
    Ok(())
}

/// Intervals the server has not seen, or has seen only up to an earlier end (one
/// that was still open when it was last sent), shaped as the API's `intervals`.
pub fn pending(conn: &Connection, limit: i64) -> rusqlite::Result<Vec<Value>> {
    let mut statement = conn.prepare(
        "SELECT uuid, session_uuid, process, is_idle, started_at, ended_at FROM app_usage
         WHERE synced_ended_at IS NULL OR synced_ended_at != ended_at ORDER BY rowid LIMIT ?1",
    )?;
    let rows = statement.query_map([limit], |row| {
        Ok(json!({
            "uuid": row.get::<_, String>(0)?,
            "session_uuid": row.get::<_, String>(1)?,
            "process": row.get::<_, String>(2)?,
            "is_idle": row.get::<_, bool>(3)?,
            "started_at": row.get::<_, String>(4)?,
            "ended_at": row.get::<_, String>(5)?,
        }))
    })?;
    rows.collect()
}

/// Marks what the server confirmed. Compares the end that was actually sent, so an
/// interval that grew while the upload was in flight is sent again next time.
pub fn mark_synced(conn: &Connection, sent: &[Value], accepted: &[String]) -> rusqlite::Result<()> {
    for interval in sent {
        let (Some(uuid), Some(ended_at)) = (interval["uuid"].as_str(), interval["ended_at"].as_str()) else { continue };
        if accepted.iter().any(|accepted| accepted == uuid) {
            conn.execute("UPDATE app_usage SET synced_ended_at = ?1 WHERE uuid = ?2 AND ended_at = ?1", (ended_at, uuid))?;
        }
    }
    Ok(())
}

/// Forgets what has been delivered, and what could never be (its session never
/// reached the server), once it is old enough that it cannot still be open.
pub fn prune(conn: &Connection, now: DateTime<Utc>) -> rusqlite::Result<()> {
    conn.execute(
        "DELETE FROM app_usage WHERE synced_ended_at = ended_at AND ended_at < ?1",
        [(now - Duration::minutes(10)).to_rfc3339()],
    )?;
    conn.execute("DELETE FROM app_usage WHERE ended_at < ?1", [(now - Duration::days(2)).to_rfc3339()])?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn database() -> Connection {
        let conn = Connection::open_in_memory().unwrap();
        crate::db::schema::run_migrations(&conn).unwrap();
        conn
    }

    fn at(seconds: i64) -> DateTime<Utc> {
        DateTime::parse_from_rfc3339("2026-09-27T09:00:00Z").unwrap().with_timezone(&Utc) + Duration::seconds(seconds)
    }

    fn sample<'a>(process: &'a str, idle: bool, seconds: i64) -> Sample<'a> {
        Sample { session_uuid: "s1", process, is_idle: idle, at: at(seconds) }
    }

    fn rows(conn: &Connection) -> Vec<(String, bool, String, String)> {
        let mut statement = conn.prepare("SELECT process, is_idle, started_at, ended_at FROM app_usage ORDER BY rowid").unwrap();
        statement.query_map([], |row| Ok((row.get(0)?, row.get(1)?, row.get(2)?, row.get(3)?))).unwrap().map(Result::unwrap).collect()
    }

    #[test]
    fn steady_use_of_one_application_is_one_growing_interval() {
        let conn = database();
        for tick in 0..5 {
            record(&conn, &sample("WINWORD.EXE", false, tick * 3)).unwrap();
        }

        let stored = rows(&conn);
        assert_eq!(stored.len(), 1);
        assert_eq!(stored[0].2, at(0).to_rfc3339());
        assert_eq!(stored[0].3, at(12 + 3).to_rfc3339());
    }

    #[test]
    fn switching_application_or_going_idle_starts_a_new_interval() {
        let conn = database();
        record(&conn, &sample("WINWORD.EXE", false, 0)).unwrap();
        record(&conn, &sample("chrome.exe", false, 3)).unwrap();
        record(&conn, &sample("chrome.exe", true, 6)).unwrap();

        let stored = rows(&conn);
        assert_eq!(stored.iter().map(|row| (row.0.as_str(), row.1)).collect::<Vec<_>>(), [("WINWORD.EXE", false), ("chrome.exe", false), ("chrome.exe", true)]);
    }

    #[test]
    fn a_long_gap_is_not_bridged() {
        let conn = database();
        record(&conn, &sample("WINWORD.EXE", false, 0)).unwrap();
        record(&conn, &sample("WINWORD.EXE", false, 600)).unwrap();

        assert_eq!(rows(&conn).len(), 2);
    }

    #[test]
    fn sessions_do_not_share_intervals() {
        let conn = database();
        record(&conn, &Sample { session_uuid: "s1", process: "a.exe", is_idle: false, at: at(0) }).unwrap();
        record(&conn, &Sample { session_uuid: "s2", process: "a.exe", is_idle: false, at: at(3) }).unwrap();

        assert_eq!(rows(&conn).len(), 2);
    }

    #[test]
    fn an_interval_is_uploaded_again_when_it_has_grown_since() {
        let conn = database();
        record(&conn, &sample("a.exe", false, 0)).unwrap();
        let sent = pending(&conn, 10).unwrap();
        let uuid = sent[0]["uuid"].as_str().unwrap().to_string();

        record(&conn, &sample("a.exe", false, 3)).unwrap(); // grows while the upload is in flight
        mark_synced(&conn, &sent, &[uuid]).unwrap();

        assert_eq!(pending(&conn, 10).unwrap().len(), 1);
    }

    #[test]
    fn a_confirmed_interval_is_not_uploaded_again() {
        let conn = database();
        record(&conn, &sample("a.exe", false, 0)).unwrap();
        let sent = pending(&conn, 10).unwrap();
        let uuid = sent[0]["uuid"].as_str().unwrap().to_string();

        mark_synced(&conn, &sent, &[uuid]).unwrap();

        assert!(pending(&conn, 10).unwrap().is_empty());
    }

    #[test]
    fn an_interval_the_server_did_not_take_stays_queued() {
        let conn = database();
        record(&conn, &sample("a.exe", false, 0)).unwrap();
        let sent = pending(&conn, 10).unwrap();

        mark_synced(&conn, &sent, &[]).unwrap();

        assert_eq!(pending(&conn, 10).unwrap().len(), 1);
    }

    #[test]
    fn pruning_drops_delivered_history_but_keeps_recent_and_undelivered_rows() {
        let conn = database();
        record(&conn, &sample("old-delivered.exe", false, 0)).unwrap();
        record(&conn, &sample("old-undelivered.exe", false, 30)).unwrap();
        let sent = pending(&conn, 10).unwrap();
        let delivered = sent[0]["uuid"].as_str().unwrap().to_string();
        mark_synced(&conn, &sent[..1], &[delivered]).unwrap();

        prune(&conn, at(0) + Duration::hours(1)).unwrap();

        let left = rows(&conn);
        assert_eq!(left.len(), 1);
        assert_eq!(left[0].0, "old-undelivered.exe");
        prune(&conn, at(0) + Duration::days(3)).unwrap();
        assert!(rows(&conn).is_empty());
    }
}
