use crate::models::AppConfig;
use rusqlite::{Connection, OptionalExtension};

fn get(conn: &Connection, key: &str) -> rusqlite::Result<Option<String>> {
    conn.query_row("SELECT value FROM app_config WHERE key = ?1", [key], |row| row.get(0))
        .optional()
}

fn set(conn: &Connection, key: &str, value: &str) -> rusqlite::Result<()> {
    conn.execute(
        "INSERT INTO app_config (key, value) VALUES (?1, ?2)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value",
        (key, value),
    )?;
    Ok(())
}

pub fn get_or_create_device_uuid(conn: &Connection) -> rusqlite::Result<String> {
    if let Some(uuid) = get(conn, "device_uuid")? {
        return Ok(uuid);
    }

    let uuid = uuid::Uuid::new_v4().to_string();
    set(conn, "device_uuid", &uuid)?;
    Ok(uuid)
}

pub fn delete_api_token(conn: &Connection) -> rusqlite::Result<()> {
    conn.execute("DELETE FROM app_config WHERE key = 'api_token'", [])?;
    Ok(())
}

pub fn get_api_base_url(conn: &Connection) -> rusqlite::Result<Option<String>> {
    get(conn, "api_base_url")
}

pub fn get_api_token(conn: &Connection) -> rusqlite::Result<Option<String>> {
    get(conn, "api_token")
}

pub fn get_config(conn: &Connection) -> rusqlite::Result<Option<AppConfig>> {
    let school_id = get(conn, "school_id")?;
    let computer_id = get(conn, "computer_id")?;
    let computer_name = get(conn, "computer_name")?;
    let api_base_url = get(conn, "api_base_url")?;
    let last_roster_synced_at = get(conn, "last_roster_synced_at")?;
    let device_uuid = get(conn, "device_uuid")?;
    let classroom_id = get(conn, "classroom_id")?.and_then(|value| value.parse().ok());
    let configuration_version = get(conn, "configuration_version")?.and_then(|value| value.parse().ok()).unwrap_or(1);

    match (school_id, computer_id, computer_name, api_base_url) {
        (Some(school_id), Some(computer_id), Some(computer_name), Some(api_base_url)) => {
            Ok(Some(AppConfig {
                school_id: school_id.parse().unwrap_or_default(),
                computer_id: computer_id.parse().unwrap_or_default(),
                computer_name,
                api_base_url,
                last_roster_synced_at,
                device_uuid,
                classroom_id,
                configuration_version,
            }))
        }
        _ => Ok(None),
    }
}

/// Persists the connection details entered on the Setup screen plus the
/// identity the backend resolved for this device via `/api/computer/me`.
pub fn save_provisioning(
    conn: &Connection,
    api_base_url: &str,
    api_token: &str,
    school_id: i64,
    computer_id: i64,
    computer_name: &str,
    device_uuid: Option<&str>,
    classroom_id: Option<i64>,
    configuration_version: i64,
) -> rusqlite::Result<()> {
    set(conn, "api_base_url", api_base_url)?;
    if !api_token.is_empty() {
        set(conn, "api_token", api_token)?;
    }
    set(conn, "school_id", &school_id.to_string())?;
    set(conn, "computer_id", &computer_id.to_string())?;
    set(conn, "computer_name", computer_name)?;
    if let Some(device_uuid) = device_uuid {
        set(conn, "device_uuid", device_uuid)?;
    }
    if let Some(classroom_id) = classroom_id {
        set(conn, "classroom_id", &classroom_id.to_string())?;
    }
    set(conn, "configuration_version", &configuration_version.to_string())?;
    Ok(())
}

pub fn update_configuration(
    conn: &Connection,
    school_id: i64,
    computer_id: i64,
    computer_name: &str,
    device_uuid: &str,
    classroom_id: Option<i64>,
    configuration_version: i64,
) -> rusqlite::Result<()> {
    set(conn, "school_id", &school_id.to_string())?;
    set(conn, "computer_id", &computer_id.to_string())?;
    set(conn, "computer_name", computer_name)?;
    set(conn, "device_uuid", device_uuid)?;
    if let Some(classroom_id) = classroom_id {
        set(conn, "classroom_id", &classroom_id.to_string())?;
    }
    set(conn, "configuration_version", &configuration_version.to_string())
}

pub fn set_last_roster_synced_at(conn: &Connection, timestamp: &str) -> rusqlite::Result<()> {
    set(conn, "last_roster_synced_at", timestamp)
}

pub fn set_last_sync_attempt_at(conn: &Connection, timestamp: &str) -> rusqlite::Result<()> {
    set(conn, "last_sync_attempt_at", timestamp)
}

pub fn get_last_sync_attempt_at(conn: &Connection) -> rusqlite::Result<Option<String>> {
    get(conn, "last_sync_attempt_at")
}

pub fn set_last_sync_success_at(conn: &Connection, timestamp: &str) -> rusqlite::Result<()> {
    set(conn, "last_sync_success_at", timestamp)
}

pub fn get_last_sync_success_at(conn: &Connection) -> rusqlite::Result<Option<String>> {
    get(conn, "last_sync_success_at")
}
