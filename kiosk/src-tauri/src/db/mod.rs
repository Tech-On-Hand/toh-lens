pub mod config_repo;
pub mod roster_repo;
mod schema;
pub mod session_repo;

use rusqlite::Connection;
use std::path::Path;

pub fn open(path: &Path) -> rusqlite::Result<Connection> {
    let conn = Connection::open(path)?;

    // Low write volume (a handful of rows/day/machine) — favor durability
    // over throughput. FULL fsyncs on every commit so a power loss never
    // loses or corrupts an already-committed login/logout record.
    conn.execute_batch(
        "PRAGMA foreign_keys = ON;
         PRAGMA synchronous = FULL;
         PRAGMA journal_mode = DELETE;",
    )?;

    schema::run_migrations(&conn)?;

    Ok(conn)
}
