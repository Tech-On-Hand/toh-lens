use crate::policy_sync::CachedPolicy;
use chrono::Utc;
use rusqlite::{Connection, OptionalExtension};

pub fn save(conn: &Connection, policy: &CachedPolicy) -> rusqlite::Result<()> {
    let payload = serde_json::to_string(policy).map_err(|e| rusqlite::Error::ToSqlConversionFailure(Box::new(e)))?;
    conn.execute(
        "INSERT INTO policy_cache (id, version, payload, updated_at) VALUES (1, ?1, ?2, ?3)
         ON CONFLICT(id) DO UPDATE SET version = excluded.version, payload = excluded.payload, updated_at = excluded.updated_at",
        (&policy.version, payload, Utc::now().to_rfc3339()),
    )?;
    Ok(())
}

/// A row that no longer parses (for example after a format change) is treated as
/// "no policy" rather than an error, and is replaced by the next sync.
pub fn load(conn: &Connection) -> rusqlite::Result<Option<CachedPolicy>> {
    let payload: Option<String> = conn
        .query_row("SELECT payload FROM policy_cache WHERE id = 1", [], |row| row.get(0))
        .optional()?;
    Ok(payload.and_then(|text| serde_json::from_str(&text).ok()))
}
