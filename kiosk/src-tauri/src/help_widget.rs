//! A small always-on-top "Ask for help" / "Chat" bar that floats over the desktop
//! while a student is logged in. After login the kiosk window is hidden (see
//! `shell_handoff.rs`), so without this a student would have no way to reach the
//! teacher from the kiosk. It is a second window running the same frontend (see
//! `HelpWidget.tsx`, chosen by window label), and grows into a chat panel when the
//! student opens the chat.

use tauri::{AppHandle, LogicalPosition, LogicalSize, Manager, WebviewWindowBuilder};

const LABEL: &str = "help";
const BAR_WIDTH: f64 = 300.0;
const BAR_HEIGHT: f64 = 56.0;
const CHAT_WIDTH: f64 = 340.0;
const CHAT_HEIGHT: f64 = 460.0;
const MARGIN: f64 = 16.0;
/// Clears the taskbar, which sits on the bottom edge of the primary monitor.
const TASKBAR_ALLOWANCE: f64 = 56.0;

/// Size and top-left position, keeping the bottom-right corner anchored so the
/// window grows up and to the left rather than off the screen.
fn layout(app: &AppHandle, expanded: bool) -> Result<(f64, f64, f64, f64), String> {
    let (width, height) = if expanded { (CHAT_WIDTH, CHAT_HEIGHT) } else { (BAR_WIDTH, BAR_HEIGHT) };
    let (x, y) = match app.primary_monitor().map_err(|e| e.to_string())? {
        Some(monitor) => {
            let size = monitor.size().to_logical::<f64>(monitor.scale_factor());
            (size.width - width - MARGIN, size.height - height - MARGIN - TASKBAR_ALLOWANCE)
        }
        None => (MARGIN, MARGIN),
    };
    Ok((width, height, x, y))
}

pub fn show(app: &AppHandle) -> Result<(), String> {
    if let Some(window) = app.get_webview_window(LABEL) {
        return window.show().map_err(|e| e.to_string());
    }

    // Built from the main window's own config rather than from scratch: WebView2
    // refuses a second window whose browser args differ from the first's (they
    // share one user-data folder), and this way they always match.
    let mut config = app
        .config()
        .app
        .windows
        .first()
        .cloned()
        .ok_or_else(|| "no window configuration to base the help widget on".to_string())?;

    let (width, height, x, y) = layout(app, false)?;
    config.label = LABEL.to_string();
    config.title = "TOH Klas help".to_string();
    config.fullscreen = false;
    config.width = width;
    config.height = height;
    config.x = Some(x);
    config.y = Some(y);
    config.resizable = false;
    config.maximizable = false;
    config.minimizable = false;
    config.closable = false;
    config.decorations = false;
    config.always_on_top = true;
    config.skip_taskbar = true;
    config.focus = false;

    WebviewWindowBuilder::from_config(app, &config)
        .map_err(|e| e.to_string())?
        .build()
        .map_err(|e| e.to_string())?;
    Ok(())
}

/// Switches between the slim bar and the chat panel.
pub fn set_expanded(app: &AppHandle, expanded: bool) -> Result<(), String> {
    let Some(window) = app.get_webview_window(LABEL) else { return Ok(()) };
    let (width, height, x, y) = layout(app, expanded)?;
    window.set_size(LogicalSize::new(width, height)).map_err(|e| e.to_string())?;
    window.set_position(LogicalPosition::new(x, y)).map_err(|e| e.to_string())?;
    if expanded {
        let _ = window.set_focus();
    }
    Ok(())
}

pub fn hide(app: &AppHandle) {
    if let Some(window) = app.get_webview_window(LABEL) {
        let _ = window.hide();
        // Next login starts as the slim bar, not a leftover open chat.
        let _ = set_expanded(app, false);
    }
}
