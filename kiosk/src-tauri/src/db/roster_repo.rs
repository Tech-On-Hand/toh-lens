use crate::models::{RosterStudentDto, StudentSummary};
use rusqlite::{Connection, OptionalExtension};

/// Full replace: the roster is small (one school) and refreshed wholesale,
/// so delete-then-bulk-insert inside one transaction is simpler and safer
/// than diffing, and still atomic if interrupted (transaction rolls back).
pub fn replace_roster(conn: &mut Connection, school_id: i64, students: &[RosterStudentDto]) -> rusqlite::Result<()> {
    let tx = conn.transaction()?;

    tx.execute("DELETE FROM students WHERE school_id = ?1", [school_id])?;

    {
        let mut stmt = tx.prepare(
            "INSERT INTO students (id, school_id, admission_number, full_name, is_active)
             VALUES (?1, ?2, ?3, ?4, 1)",
        )?;
        for student in students {
            stmt.execute((student.id, school_id, &student.admission_number, &student.full_name))?;
        }
    }

    tx.commit()
}

pub fn find_student(conn: &Connection, school_id: i64, admission_number: &str) -> rusqlite::Result<Option<StudentSummary>> {
    conn.query_row(
        "SELECT id, admission_number, full_name FROM students
         WHERE school_id = ?1 AND admission_number = ?2 AND is_active = 1",
        (school_id, admission_number),
        |row| {
            Ok(StudentSummary {
                id: row.get(0)?,
                admission_number: row.get(1)?,
                full_name: row.get(2)?,
            })
        },
    )
    .optional()
}

pub fn count(conn: &Connection, school_id: i64) -> rusqlite::Result<i64> {
    conn.query_row("SELECT COUNT(*) FROM students WHERE school_id = ?1", [school_id], |row| row.get(0))
}
