use crate::models::{LoginSessionRecord, StudentSummary, SyncSessionPayload};
use chrono::Utc;
use rusqlite::{Connection, OptionalExtension};
use uuid::Uuid;

fn now_iso() -> String {
    Utc::now().to_rfc3339()
}

/// Closes any session left open on this computer (e.g. the previous student
/// never clicked Log Out) before starting a new one, so `login_sessions`
/// never has more than one open row per computer at a time.
fn close_dangling_open_session(conn: &Connection, computer_id: i64) -> rusqlite::Result<()> {
    let now = now_iso();
    conn.execute(
        "UPDATE login_sessions
         SET logout_time = ?1, synced = 0, updated_at = ?1
         WHERE computer_id = ?2 AND logout_time IS NULL",
        (&now, computer_id),
    )?;
    Ok(())
}

pub fn insert_login(
    conn: &Connection,
    school_id: i64,
    computer_id: i64,
    student: &StudentSummary,
) -> rusqlite::Result<LoginSessionRecord> {
    close_dangling_open_session(conn, computer_id)?;

    let session_uuid = Uuid::new_v4().to_string();
    let login_time = now_iso();

    conn.execute(
        "INSERT INTO login_sessions
            (session_uuid, school_id, computer_id, student_id, admission_number, full_name, login_time, logout_time, synced, created_at, updated_at)
         VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, NULL, 0, ?7, ?7)",
        (
            &session_uuid,
            school_id,
            computer_id,
            student.id,
            &student.admission_number,
            &student.full_name,
            &login_time,
        ),
    )?;

    Ok(LoginSessionRecord {
        session_uuid,
        admission_number: student.admission_number.clone(),
        full_name: student.full_name.clone(),
        login_time,
        logout_time: None,
    })
}

pub fn close_logout(conn: &Connection, session_uuid: &str) -> rusqlite::Result<()> {
    let now = now_iso();
    conn.execute(
        "UPDATE login_sessions
         SET logout_time = ?1, synced = 0, updated_at = ?1
         WHERE session_uuid = ?2",
        (&now, session_uuid),
    )?;
    Ok(())
}

pub fn find_open_session(conn: &Connection, computer_id: i64) -> rusqlite::Result<Option<LoginSessionRecord>> {
    conn.query_row(
        "SELECT session_uuid, admission_number, full_name, login_time, logout_time
         FROM login_sessions
         WHERE computer_id = ?1 AND logout_time IS NULL
         ORDER BY id DESC LIMIT 1",
        [computer_id],
        map_row,
    )
    .optional()
}

/// The session that was open at `at` (an RFC 3339 instant), so an event that
/// happened during one student's session is never credited to the next one.
pub fn session_uuid_at(conn: &Connection, computer_id: i64, at: &str) -> rusqlite::Result<Option<String>> {
    conn.query_row(
        "SELECT session_uuid FROM login_sessions
         WHERE computer_id = ?1 AND login_time <= ?2 AND (logout_time IS NULL OR logout_time >= ?2)
         ORDER BY id DESC LIMIT 1",
        (computer_id, at),
        |row| row.get(0),
    )
    .optional()
}

fn map_row(row: &rusqlite::Row) -> rusqlite::Result<LoginSessionRecord> {
    Ok(LoginSessionRecord {
        session_uuid: row.get(0)?,
        admission_number: row.get(1)?,
        full_name: row.get(2)?,
        login_time: row.get(3)?,
        logout_time: row.get(4)?,
    })
}

pub fn unsynced_count(conn: &Connection) -> rusqlite::Result<i64> {
    conn.query_row("SELECT COUNT(*) FROM login_sessions WHERE synced = 0", [], |row| row.get(0))
}

/// Rows are converted straight into the sync API's payload shape here since
/// that's their only consumer.
pub fn unsynced_batch(conn: &Connection, limit: i64) -> rusqlite::Result<Vec<SyncSessionPayload>> {
    let mut stmt = conn.prepare(
        "SELECT session_uuid, admission_number, login_time, logout_time
         FROM login_sessions WHERE synced = 0 ORDER BY id ASC LIMIT ?1",
    )?;

    let rows = stmt.query_map([limit], |row| {
        Ok(SyncSessionPayload {
            uuid: row.get(0)?,
            admission_number: row.get(1)?,
            login_time: row.get(2)?,
            logout_time: row.get(3)?,
        })
    })?;

    rows.collect()
}

pub fn mark_synced(conn: &Connection, uuids: &[String]) -> rusqlite::Result<()> {
    let now = now_iso();
    let mut stmt = conn.prepare(
        "UPDATE login_sessions SET synced = 1, synced_at = ?1 WHERE session_uuid = ?2",
    )?;
    for uuid in uuids {
        stmt.execute((&now, uuid))?;
    }
    Ok(())
}
