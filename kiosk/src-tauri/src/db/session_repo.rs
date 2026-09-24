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

/// Closes whatever session was left open on this computer, if any — called once
/// at kiosk startup. A crash, forced power-off, restart, or waking from sleep all
/// leave `logout_time` unset with no chance for the student to click Log Out; the
/// kiosk must never resume straight into that session, since whoever is at the
/// keyboard now might not be who was signed in before. Returns the session that
/// was closed, if there was one, so the caller can react (e.g. reclaim the
/// desktop hand-off).
pub fn reclaim_open_session(conn: &Connection, computer_id: i64) -> rusqlite::Result<Option<LoginSessionRecord>> {
    let open = find_open_session(conn, computer_id)?;
    if open.is_some() {
        close_dangling_open_session(conn, computer_id)?;
    }
    Ok(open)
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

#[cfg(test)]
mod tests {
    use super::*;

    fn database() -> Connection {
        let conn = Connection::open_in_memory().unwrap();
        crate::db::schema::run_migrations(&conn).unwrap();
        conn
    }

    fn student(admission_number: &str) -> StudentSummary {
        StudentSummary { id: 1, admission_number: admission_number.to_string(), full_name: "A Student".to_string() }
    }

    #[test]
    fn reclaiming_with_nothing_open_does_nothing_and_reports_none() {
        let conn = database();
        assert!(reclaim_open_session(&conn, 1).unwrap().is_none());
    }

    #[test]
    fn reclaiming_closes_the_open_session_and_returns_it() {
        let conn = database();
        let opened = insert_login(&conn, 1, 1, &student("1001")).unwrap();

        let reclaimed = reclaim_open_session(&conn, 1).unwrap().expect("a session was open");

        assert_eq!(reclaimed.session_uuid, opened.session_uuid);
        assert!(find_open_session(&conn, 1).unwrap().is_none());
    }

    #[test]
    fn reclaiming_only_touches_the_named_computer() {
        let conn = database();
        insert_login(&conn, 1, 1, &student("1001")).unwrap();
        let other = insert_login(&conn, 1, 2, &student("1002")).unwrap();

        reclaim_open_session(&conn, 1).unwrap();

        let still_open = find_open_session(&conn, 2).unwrap().expect("computer 2's session is untouched");
        assert_eq!(still_open.session_uuid, other.session_uuid);
    }

    #[test]
    fn reclaiming_is_safe_to_call_again_once_nothing_is_open() {
        let conn = database();
        insert_login(&conn, 1, 1, &student("1001")).unwrap();
        reclaim_open_session(&conn, 1).unwrap();

        assert!(reclaim_open_session(&conn, 1).unwrap().is_none());
    }
}
