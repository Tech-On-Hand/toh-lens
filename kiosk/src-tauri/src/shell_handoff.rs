//! SRS FR-1.5 / FR-1.6: hand off to the normal Windows desktop on login,
//! reclaim it on logout.
//!
//! The release implementation actually spawns/kills `explorer.exe` and
//! hides/shows the kiosk window. That is only safe on a machine where
//! `explorer.exe` has been replaced as the shell for the student account
//! (see `provisioning/windows-kiosk-hardening.md`) — on an ordinary Windows
//! install, `explorer.exe` *is* the real desktop shell, so killing it would
//! take down the whole desktop, not just this app's stand-in for one.
//!
//! The debug build (every dev machine, including `npm run tauri dev`) is a
//! deliberate no-op so this can never happen accidentally while developing.
//! Known limitation: a crash after login followed by a relaunch does not
//! currently re-detect an already-running handed-off `explorer.exe` — the
//! new process only tracks handles it spawns itself.

use crate::state::AppState;
use tauri::AppHandle;

#[cfg(not(debug_assertions))]
pub fn launch_desktop(app: &AppHandle, state: &AppState) {
    use std::process::Command;
    use tauri::Manager;

    {
        let mut child_slot = state.desktop_child.lock().unwrap();
        if child_slot.is_some() {
            // Already handed off (shouldn't happen — insert_login closes any
            // dangling session first — but never spawn a second explorer.exe).
            return;
        }

        match Command::new("explorer.exe").spawn() {
            Ok(child) => *child_slot = Some(child),
            Err(e) => {
                log::error!("failed to launch explorer.exe: {e}");
                return;
            }
        }
    }

    if let Some(window) = app.get_webview_window("main") {
        let _ = window.hide();
    }
}

#[cfg(not(debug_assertions))]
pub fn reclaim_desktop(app: &AppHandle, state: &AppState) {
    use tauri::Manager;

    {
        let mut child_slot = state.desktop_child.lock().unwrap();
        if let Some(mut child) = child_slot.take() {
            let _ = child.kill();
            let _ = child.wait();
        }
    }

    if let Some(window) = app.get_webview_window("main") {
        let _ = window.show();
        let _ = window.set_focus();
    }
}

/// Brings the kiosk window back over the (handed-off) desktop, e.g. to show a
/// teacher's announcement. The window is fullscreen and always-on-top, so the
/// student has to acknowledge it before carrying on.
#[cfg(not(debug_assertions))]
pub fn present_window(app: &AppHandle) {
    use tauri::Manager;

    if let Some(window) = app.get_webview_window("main") {
        let _ = window.show();
        let _ = window.set_focus();
    }
}

/// Puts the desktop back after `present_window` — but only if a student is
/// logged in (the desktop is currently handed off). With nobody logged in the
/// kiosk window IS the screen and must stay up.
#[cfg(not(debug_assertions))]
pub fn release_window(app: &AppHandle, state: &AppState) {
    use tauri::Manager;

    if state.desktop_child.lock().unwrap().is_none() {
        return;
    }
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.hide();
    }
}

#[cfg(debug_assertions)]
pub fn present_window(_app: &AppHandle) {}

#[cfg(debug_assertions)]
pub fn release_window(_app: &AppHandle, _state: &AppState) {}

#[cfg(debug_assertions)]
pub fn launch_desktop(_app: &AppHandle, _state: &AppState) {}

#[cfg(debug_assertions)]
pub fn reclaim_desktop(_app: &AppHandle, _state: &AppState) {}
