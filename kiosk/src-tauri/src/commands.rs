use crate::db::{config_repo, roster_repo, session_repo};
use crate::models::{AppConfig, LoginSessionRecord, RosterStatus, StudentSummary, SyncResult, SyncStatus};
use crate::state::AppState;
use tauri::State;

/// Every admission-number failure — not found, inactive, malformed, empty —
/// collapses to this one message. The real reason is only ever logged
/// locally (FR-1.4: never reveal which part of the entry was wrong).
const GENERIC_LOGIN_ERROR: &str = "Admission number not recognized. Please try again.";
const NOT_CONFIGURED_ERROR: &str = "This computer has not been set up yet.";

#[tauri::command]
pub fn get_config(state: State<AppState>) -> Result<Option<AppConfig>, String> {
    let conn = state.db.lock().unwrap();
    config_repo::get_config(&conn).map_err(|e| e.to_string())
}

#[tauri::command]
pub async fn save_config(state: State<'_, AppState>, api_base_url: String, api_token: String) -> Result<AppConfig, String> {
    let api_base_url = api_base_url.trim().to_string();
    let api_token = api_token.trim().to_string();

    let computer = crate::api_client::fetch_computer_me(&state.http, &api_base_url, &api_token)
        .await
        .map_err(|e| format!("Could not reach server or token is invalid: {e}"))?;

    let conn = state.db.lock().unwrap();
    crate::credential_store::save_token(&api_token)?;
    config_repo::save_provisioning(
        &conn,
        &api_base_url,
        "",
        computer.school_id,
        computer.id,
        &computer.name,
        computer.device_uuid.as_deref(),
        computer.classroom_id,
        1,
    )
        .map_err(|e| e.to_string())?;

    config_repo::get_config(&conn)
        .map_err(|e| e.to_string())?
        .ok_or_else(|| "failed to read back saved configuration".to_string())
}

#[tauri::command]
pub async fn enroll_device(
    state: State<'_, AppState>,
    api_base_url: String,
    enrollment_code: String,
    device_name: String,
) -> Result<AppConfig, String> {
    let api_base_url = api_base_url.trim().to_string();
    let device_uuid = {
        let conn = state.db.lock().unwrap();
        config_repo::get_or_create_device_uuid(&conn).map_err(|e| e.to_string())?
    };
    let hostname = std::env::var("COMPUTERNAME").unwrap_or_else(|_| "Windows PC".into());
    let result = crate::api_client::enroll_device(
        &state.http,
        &api_base_url,
        crate::models::EnrollmentRequest {
            code: enrollment_code.trim().to_string(),
            device_uuid,
            name: device_name.trim().to_string(),
            hostname,
            operating_system: format!("Windows {}", std::env::consts::ARCH),
            agent_version: env!("CARGO_PKG_VERSION").into(),
        },
    )
    .await?;

    crate::credential_store::save_token(&result.token)?;
    let conn = state.db.lock().unwrap();
    config_repo::save_provisioning(
        &conn,
        &api_base_url,
        "",
        result.device.school_id,
        result.device.id,
        &result.device.name,
        Some(&result.device.device_uuid),
        result.device.classroom_id,
        result.device.configuration_version,
    )
    .map_err(|e| e.to_string())?;

    config_repo::get_config(&conn)
        .map_err(|e| e.to_string())?
        .ok_or_else(|| "failed to read back enrolled device configuration".to_string())
}

#[tauri::command]
pub fn get_roster_cache_status(state: State<AppState>) -> Result<RosterStatus, String> {
    let conn = state.db.lock().unwrap();
    let config = config_repo::get_config(&conn).map_err(|e| e.to_string())?;

    let Some(config) = config else {
        return Ok(RosterStatus { student_count: 0, last_synced_at: None });
    };

    let student_count = roster_repo::count(&conn, config.school_id).map_err(|e| e.to_string())?;

    Ok(RosterStatus { student_count, last_synced_at: config.last_roster_synced_at })
}

#[tauri::command]
pub async fn refresh_roster(state: State<'_, AppState>) -> Result<RosterStatus, String> {
    let (base_url, token, school_id) = {
        let conn = state.db.lock().unwrap();
        let config = config_repo::get_config(&conn).map_err(|e| e.to_string())?.ok_or(NOT_CONFIGURED_ERROR)?;
        let token = crate::credential_store::get_token().ok_or(NOT_CONFIGURED_ERROR)?;
        (config.api_base_url, token, config.school_id)
    };

    let roster = crate::api_client::fetch_roster(&state.http, &base_url, &token)
        .await
        .map_err(|e| format!("Could not refresh roster: {e}"))?;

    let now = chrono::Utc::now().to_rfc3339();
    {
        let mut conn = state.db.lock().unwrap();
        roster_repo::replace_roster(&mut conn, school_id, &roster.students).map_err(|e| e.to_string())?;
        config_repo::set_last_roster_synced_at(&conn, &now).map_err(|e| e.to_string())?;
    }

    get_roster_cache_status(state)
}

#[tauri::command]
pub fn validate_admission_number(state: State<AppState>, admission_number: String) -> Result<StudentSummary, String> {
    let conn = state.db.lock().unwrap();
    let config = config_repo::get_config(&conn).map_err(|_| GENERIC_LOGIN_ERROR)?.ok_or(NOT_CONFIGURED_ERROR)?;

    let admission_number = admission_number.trim();
    if admission_number.is_empty() {
        return Err(GENERIC_LOGIN_ERROR.to_string());
    }

    roster_repo::find_student(&conn, config.school_id, admission_number)
        .map_err(|_| GENERIC_LOGIN_ERROR.to_string())?
        .ok_or_else(|| GENERIC_LOGIN_ERROR.to_string())
}

#[tauri::command]
pub fn record_login(app: tauri::AppHandle, state: State<AppState>, admission_number: String) -> Result<LoginSessionRecord, String> {
    let record = {
        let conn = state.db.lock().unwrap();
        let config = config_repo::get_config(&conn).map_err(|_| GENERIC_LOGIN_ERROR)?.ok_or(NOT_CONFIGURED_ERROR)?;

        let admission_number = admission_number.trim();
        if admission_number.is_empty() {
            return Err(GENERIC_LOGIN_ERROR.to_string());
        }

        // Re-validate here rather than trusting a prior validate_admission_number
        // call — this is the single source of truth for who is allowed in.
        let student = roster_repo::find_student(&conn, config.school_id, admission_number)
            .map_err(|_| GENERIC_LOGIN_ERROR.to_string())?
            .ok_or_else(|| GENERIC_LOGIN_ERROR.to_string())?;

        session_repo::insert_login(&conn, config.school_id, config.computer_id, &student).map_err(|e| e.to_string())?
    };

    // FR-1.5: hand off to the normal desktop. No-op in dev builds — see
    // shell_handoff.rs for why this must never run on a developer machine.
    crate::shell_handoff::launch_desktop(&app, &state);

    Ok(record)
}

#[tauri::command]
pub fn record_logout(app: tauri::AppHandle, state: State<AppState>, session_uuid: String) -> Result<(), String> {
    {
        let conn = state.db.lock().unwrap();
        session_repo::close_logout(&conn, &session_uuid).map_err(|e| e.to_string())?;
    }

    // FR-1.6: reclaim the desktop before returning to the keypad.
    crate::shell_handoff::reclaim_desktop(&app, &state);

    // Best-effort push right after logout so completed sessions sync
    // promptly instead of waiting for the next periodic tick.
    let state_for_sync = state.inner().clone();
    tauri::async_runtime::spawn(async move {
        crate::sync::try_sync(&state_for_sync).await;
    });

    Ok(())
}

#[tauri::command]
pub fn get_open_session(state: State<AppState>) -> Result<Option<LoginSessionRecord>, String> {
    let conn = state.db.lock().unwrap();
    let Some(config) = config_repo::get_config(&conn).map_err(|e| e.to_string())? else {
        return Ok(None);
    };

    session_repo::find_open_session(&conn, config.computer_id).map_err(|e| e.to_string())
}

#[tauri::command]
pub async fn sync_now(state: State<'_, AppState>) -> Result<SyncResult, String> {
    Ok(crate::sync::try_sync(&state).await)
}

#[tauri::command]
pub async fn get_sync_status(state: State<'_, AppState>) -> Result<SyncStatus, String> {
    let base_url = {
        let conn = state.db.lock().unwrap();
        config_repo::get_api_base_url(&conn).map_err(|e| e.to_string())?
    };

    let is_online = match &base_url {
        Some(base_url) => crate::api_client::ping(&state.http, base_url).await,
        None => false,
    };

    let conn = state.db.lock().unwrap();
    let unsynced_count = session_repo::unsynced_count(&conn).map_err(|e| e.to_string())?;
    let last_attempt_at = config_repo::get_last_sync_attempt_at(&conn).map_err(|e| e.to_string())?;
    let last_success_at = config_repo::get_last_sync_success_at(&conn).map_err(|e| e.to_string())?;

    Ok(SyncStatus { is_online, unsynced_count, last_attempt_at, last_success_at })
}

/// Debug-only helpers so the login/keypad flow can be exercised fully
/// offline, before a backend exists to provision against or sync to.
/// Both are dropped from the invoke handler once the real Setup screen
/// (build Phase C) is wired up.
#[cfg(debug_assertions)]
#[tauri::command]
pub fn seed_demo_config(state: State<AppState>) -> Result<AppConfig, String> {
    let conn = state.db.lock().unwrap();
    config_repo::save_provisioning(&conn, "http://127.0.0.1:8000", "", 1, 1, "Dev Kiosk", None, None, 1)
        .map_err(|e| e.to_string())?;

    config_repo::get_config(&conn)
        .map_err(|e| e.to_string())?
        .ok_or_else(|| "failed to read back seeded configuration".to_string())
}

#[cfg(debug_assertions)]
#[tauri::command]
pub fn seed_demo_roster(state: State<AppState>) -> Result<RosterStatus, String> {
    use crate::models::RosterStudentDto;

    let mut conn = state.db.lock().unwrap();
    let demo_students = vec![
        RosterStudentDto { id: 1, admission_number: "1001".into(), full_name: "Amara Otieno".into() },
        RosterStudentDto { id: 2, admission_number: "1002".into(), full_name: "Brian Mwangi".into() },
        RosterStudentDto { id: 3, admission_number: "1003".into(), full_name: "Cynthia Wanjiru".into() },
    ];

    roster_repo::replace_roster(&mut conn, 1, &demo_students).map_err(|e| e.to_string())?;

    Ok(RosterStatus {
        student_count: demo_students.len() as i64,
        last_synced_at: None,
    })
}
