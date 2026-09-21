//! A window this process owns and paints itself, so every spike in this crate can
//! capture something real without ever touching the desktop. Shared by
//! `main.rs` and `bin/webrtc_send.rs`.

use std::sync::atomic::{AtomicU32, Ordering};

use windows::core::w;
use windows::Win32::Foundation::{COLORREF, HINSTANCE, HWND, LPARAM, LRESULT, RECT, WPARAM};
use windows::Win32::Graphics::Gdi::{
    BeginPaint, BitBlt, CreateCompatibleBitmap, CreateCompatibleDC, CreateSolidBrush, DeleteDC, DeleteObject, EndPaint, FillRect,
    InvalidateRect, SelectObject, HGDIOBJ, PAINTSTRUCT, SRCCOPY,
};
use windows::Win32::System::LibraryLoader::GetModuleHandleW;
use windows::Win32::UI::WindowsAndMessaging::{
    CreateWindowExW, DefWindowProcW, DispatchMessageW, GetClientRect, GetMessageW, PostQuitMessage, RegisterClassW,
    SetLayeredWindowAttributes, SetTimer, ShowWindow, TranslateMessage, LWA_ALPHA, MSG, SW_SHOWNOACTIVATE, WM_DESTROY, WM_PAINT, WM_TIMER,
    WNDCLASSW, WS_EX_LAYERED, WS_EX_NOACTIVATE, WS_EX_TOOLWINDOW, WS_EX_TRANSPARENT, WS_POPUP,
};

/// Far outside any real monitor arrangement, so the window is never seen. Windows
/// does NOT keep repainting a window placed here, so it is only useful as a
/// deliberately-broken baseline, never for an actual capture.
pub const OFFSCREEN: i32 = -20000;

static TICK: AtomicU32 = AtomicU32::new(0);

unsafe extern "system" fn window_proc(hwnd: HWND, message: u32, wparam: WPARAM, lparam: LPARAM) -> LRESULT {
    match message {
        WM_TIMER => {
            TICK.fetch_add(1, Ordering::Relaxed);
            let _ = InvalidateRect(Some(hwnd), None, false);
            LRESULT(0)
        }
        WM_PAINT => {
            paint(hwnd);
            LRESULT(0)
        }
        WM_DESTROY => {
            PostQuitMessage(0);
            LRESULT(0)
        }
        _ => DefWindowProcW(hwnd, message, wparam, lparam),
    }
}

/// Dark background, a pure red block top-left, a pure blue block bottom-right, and a
/// green bar that moves every tick so every frame really is different.
unsafe fn paint(hwnd: HWND) {
    let mut ps = PAINTSTRUCT::default();
    let screen = BeginPaint(hwnd, &mut ps);
    let mut client = RECT::default();
    let _ = GetClientRect(hwnd, &mut client);
    let (width, height) = (client.right, client.bottom);

    // Draw off to the side and copy in one step so a capture can never see half a frame.
    let hdc = CreateCompatibleDC(Some(screen));
    let bitmap = CreateCompatibleBitmap(screen, width, height);
    let previous = SelectObject(hdc, HGDIOBJ(bitmap.0));

    let fill = |rect: RECT, color: u32| {
        let brush = CreateSolidBrush(COLORREF(color));
        FillRect(hdc, &rect, brush);
        let _ = DeleteObject(HGDIOBJ(brush.0));
    };

    fill(RECT { left: 0, top: 0, right: width, bottom: height }, 0x001E1E1E);
    fill(RECT { left: 0, top: 0, right: 64, bottom: 64 }, 0x0000_00FF); // COLORREF is 0x00BBGGRR: red
    fill(RECT { left: width - 64, top: height - 64, right: width, bottom: height }, 0x00FF_0000); // blue
    let x = 100 + (TICK.load(Ordering::Relaxed) as i32 * 7) % (width - 300).max(1);
    fill(RECT { left: x, top: height / 2 - 20, right: x + 120, bottom: height / 2 + 20 }, 0x0000_C800); // green

    let _ = BitBlt(screen, 0, 0, width, height, Some(hdc), 0, 0, SRCCOPY);
    SelectObject(hdc, previous);
    let _ = DeleteObject(HGDIOBJ(bitmap.0));
    let _ = DeleteDC(hdc);
    let _ = EndPaint(hwnd, &ps);
}

/// Creates the window on its own thread; returns its handle and a way to join that
/// thread once it has been sent `WM_CLOSE` and destroyed.
///
/// `ghost`: on screen (so the compositor keeps updating it) but 1/255 opaque and
/// click-through, so nobody can see or interact with it. When false, the window is
/// placed off-screen instead, which is only useful to demonstrate that this does NOT
/// work for a real capture (see `OFFSCREEN`).
pub fn spawn_window(width: i32, height: i32, ghost: bool) -> (isize, std::thread::JoinHandle<()>) {
    let (sender, receiver) = std::sync::mpsc::channel();
    let handle = std::thread::spawn(move || unsafe {
        let module = GetModuleHandleW(None).expect("module handle");
        let instance = HINSTANCE(module.0);
        let class = WNDCLASSW { lpfnWndProc: Some(window_proc), hInstance: instance, lpszClassName: w!("TohKlasCaptureSpike"), ..Default::default() };
        RegisterClassW(&class);

        let (style, position) = if ghost {
            (WS_EX_TOOLWINDOW | WS_EX_NOACTIVATE | WS_EX_LAYERED | WS_EX_TRANSPARENT, 0)
        } else {
            (WS_EX_TOOLWINDOW | WS_EX_NOACTIVATE, OFFSCREEN)
        };
        let hwnd = CreateWindowExW(
            style,
            w!("TohKlasCaptureSpike"),
            w!("TOHKLAS-NATIVE-SPIKE"),
            WS_POPUP,
            position,
            position,
            width,
            height,
            None,
            None,
            Some(instance),
            None,
        )
        .expect("create window");
        if ghost {
            let _ = SetLayeredWindowAttributes(hwnd, COLORREF(0), 1, LWA_ALPHA);
        }
        let _ = ShowWindow(hwnd, SW_SHOWNOACTIVATE);
        SetTimer(Some(hwnd), 1, 16, None);
        sender.send(hwnd.0 as isize).unwrap();

        let mut message = MSG::default();
        while GetMessageW(&mut message, None, 0, 0).as_bool() {
            let _ = TranslateMessage(&message);
            DispatchMessageW(&message);
        }
    });
    (receiver.recv().unwrap(), handle)
}
