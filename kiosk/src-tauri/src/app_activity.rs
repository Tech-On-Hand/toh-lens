//! Records which desktop application the signed-in student has in front, and
//! uploads it (see `db/app_usage_repo.rs`). Runs on the same tick as everything
//! else that polls. Only the process name is read, never the window title, since
//! titles carry document names and other personal content.

use crate::db::{app_usage_repo, config_repo, session_repo};
use crate::state::AppState;
use chrono::Utc;
use serde_json::{json, Value};

/// No keyboard or mouse input for this long counts as idle rather than as time in
/// whichever application was last in front.
const IDLE_AFTER_SECONDS: u64 = 60;
/// Upload every this many ticks (about 30 seconds).
const UPLOAD_EVERY: u64 = 10;
const UPLOAD_BATCH: i64 = 200;

#[cfg(windows)]
mod platform {
    use windows::core::PWSTR;
    use windows::Win32::Foundation::CloseHandle;
    use windows::Win32::System::SystemInformation::GetTickCount;
    use windows::Win32::System::Threading::{OpenProcess, QueryFullProcessImageNameW, PROCESS_NAME_WIN32, PROCESS_QUERY_LIMITED_INFORMATION};
    use windows::Win32::UI::Input::KeyboardAndMouse::{GetLastInputInfo, LASTINPUTINFO};
    use windows::Win32::UI::WindowsAndMessaging::{GetForegroundWindow, GetWindowThreadProcessId};

    /// The file name of the program owning the foreground window (`WINWORD.EXE`), or
    /// `None` when there is no such window (the lock screen, a switch in progress) or
    /// it cannot be read.
    pub fn foreground_process() -> Option<String> {
        unsafe {
            let window = GetForegroundWindow();
            if window.0.is_null() {
                return None;
            }
            let mut process_id = 0u32;
            GetWindowThreadProcessId(window, Some(&mut process_id));
            if process_id == 0 {
                return None;
            }

            let process = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, false, process_id).ok()?;
            let mut buffer = [0u16; 1024];
            let mut length = buffer.len() as u32;
            let queried = QueryFullProcessImageNameW(process, PROCESS_NAME_WIN32, PWSTR(buffer.as_mut_ptr()), &mut length);
            let _ = CloseHandle(process);
            queried.ok()?;

            let path = String::from_utf16_lossy(&buffer[..length as usize]);
            path.rsplit(['\\', '/']).next().map(str::to_owned).filter(|name| !name.is_empty())
        }
    }

    /// Seconds since the last keyboard or mouse input.
    pub fn idle_seconds() -> u64 {
        unsafe {
            let mut info = LASTINPUTINFO { cbSize: std::mem::size_of::<LASTINPUTINFO>() as u32, dwTime: 0 };
            if !GetLastInputInfo(&mut info).as_bool() {
                return 0;
            }
            u64::from(GetTickCount().wrapping_sub(info.dwTime)) / 1000
        }
    }
}

#[cfg(not(windows))]
mod platform {
    pub fn foreground_process() -> Option<String> {
        None
    }
    pub fn idle_seconds() -> u64 {
        0
    }
}

/// The kiosk's own windows are not the student's activity.
fn is_own_process(process: &str) -> bool {
    std::env::current_exe()
        .ok()
        .and_then(|path| path.file_name().map(|name| name.to_string_lossy().eq_ignore_ascii_case(process)))
        .unwrap_or(false)
}

/// Seconds since the last keyboard or mouse input anywhere on the computer.
pub fn system_idle_seconds() -> u64 {
    platform::idle_seconds()
}

fn open_session_uuid(state: &AppState) -> Option<String> {
    let conn = state.db.lock().unwrap();
    let config = config_repo::get_config(&conn).ok().flatten()?;
    session_repo::find_open_session(&conn, config.computer_id).ok().flatten().map(|session| session.session_uuid)
}

fn sample(state: &AppState) {
    let Some(session_uuid) = open_session_uuid(state) else { return };
    let Some(process) = platform::foreground_process() else { return };
    if is_own_process(&process) {
        return;
    }

    let conn = state.db.lock().unwrap();
    let observation = app_usage_repo::Sample {
        session_uuid: &session_uuid,
        process: &process,
        is_idle: platform::idle_seconds() >= IDLE_AFTER_SECONDS,
        at: Utc::now(),
    };
    if let Err(error) = app_usage_repo::record(&conn, &observation) {
        log::warn!("app activity: could not record a sample: {error}");
    }
}

async fn upload(state: &AppState) {
    let (base_url, token, batch) = {
        let conn = state.db.lock().unwrap();
        let _ = app_usage_repo::prune(&conn, Utc::now());
        (
            config_repo::get_api_base_url(&conn).ok().flatten(),
            crate::credential_store::get_token(),
            app_usage_repo::pending(&conn, UPLOAD_BATCH).unwrap_or_default(),
        )
    };
    let (Some(base_url), Some(token), false) = (base_url, token, batch.is_empty()) else { return };

    let Ok(reply) = crate::api_client::device_json(&state.http, &base_url, &token, reqwest::Method::POST, "app-activity", Some(json!({ "intervals": batch }))).await else {
        return; // offline or the server is having a moment: it stays queued
    };
    let accepted: Vec<String> = reply["accepted"].as_array().map(|list| list.iter().filter_map(Value::as_str).map(str::to_owned).collect()).unwrap_or_default();

    let conn = state.db.lock().unwrap();
    let _ = app_usage_repo::mark_synced(&conn, &batch, &accepted);
}

pub async fn tick(state: &AppState, tick_number: u64) {
    sample(state);
    if tick_number % UPLOAD_EVERY == 0 {
        upload(state).await;
    }
}

#[cfg(all(test, windows))]
mod tests {
    use super::*;

    /// Needs a real desktop with a window in front, so it is run by hand:
    /// `cargo test --lib foreground -- --ignored --nocapture`.
    #[test]
    #[ignore]
    fn reads_the_real_foreground_process_and_idle_time() {
        let process = platform::foreground_process().expect("a foreground window with a readable process");
        println!("foreground: {process}, idle for {}s", platform::idle_seconds());
        assert!(process.to_lowercase().ends_with(".exe"));
    }
}
