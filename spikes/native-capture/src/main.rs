//! Spike: can the Student Agent capture a window with Windows Graphics Capture
//! straight from Rust, with no browser involved?
//!
//! It creates its OWN window, paints a known animated pattern into it, captures that
//! window, and checks the captured pixels against the pattern. It never captures the
//! desktop, shows nothing you can see (`--ghost`: on screen but 1/255 opaque and
//! click-through; the default off-screen placement is NOT updated by Windows and
//! yields a single frame), and has a hard time limit. `--encode` also writes the
//! frames to an H.264 file to measure that cost.
//!
//!   cargo build --release && target/release/native-capture-spike --ghost --encode

use std::sync::atomic::{AtomicBool, AtomicU32, AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use windows::core::w;
use windows::Win32::Foundation::{COLORREF, FILETIME, HINSTANCE, HWND, LPARAM, LRESULT, RECT, WPARAM};
use windows::Win32::Graphics::Gdi::{
    BeginPaint, BitBlt, CreateCompatibleBitmap, CreateCompatibleDC, CreateSolidBrush, DeleteDC, DeleteObject, EndPaint, FillRect,
    InvalidateRect, SelectObject, HGDIOBJ, PAINTSTRUCT, SRCCOPY,
};
use windows::Win32::System::LibraryLoader::GetModuleHandleW;
use windows::Win32::System::Threading::{GetCurrentProcess, GetProcessTimes};
use windows::Win32::UI::WindowsAndMessaging::{
    CreateWindowExW, DefWindowProcW, DispatchMessageW, GetClientRect, GetMessageW, PostMessageW, PostQuitMessage, RegisterClassW,
    SetLayeredWindowAttributes, SetTimer, ShowWindow, TranslateMessage, LWA_ALPHA, MSG, SW_SHOWNOACTIVATE, WM_CLOSE, WM_DESTROY, WM_PAINT,
    WM_TIMER, WNDCLASSW, WS_EX_LAYERED, WS_EX_NOACTIVATE, WS_EX_TOOLWINDOW, WS_EX_TRANSPARENT, WS_POPUP,
};
use windows_capture::capture::{Context, GraphicsCaptureApiHandler};
use windows_capture::encoder::{AudioSettingsBuilder, ContainerSettingsBuilder, VideoEncoder, VideoSettingsBuilder, VideoSettingsSubType};
use windows_capture::frame::Frame;
use windows_capture::graphics_capture_api::InternalCaptureControl;
use windows_capture::settings::{
    ColorFormat, CursorCaptureSettings, DirtyRegionSettings, DrawBorderSettings, MinimumUpdateIntervalSettings, SecondaryWindowSettings,
    Settings,
};
use windows_capture::window::Window;

/// Far outside any real monitor arrangement, so the window is never seen.
const OFFSCREEN: i32 = -20000;
const RUN_SECONDS: u64 = 5;

static TICK: AtomicU32 = AtomicU32::new(0);

// --- the window being captured ---------------------------------------------------

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

/// Creates the window on its own thread; returns its handle and a way to close it.
fn spawn_window(width: i32, height: i32, ghost: bool) -> (isize, std::thread::JoinHandle<()>) {
    let (sender, receiver) = std::sync::mpsc::channel();
    let handle = std::thread::spawn(move || unsafe {
        let module = GetModuleHandleW(None).expect("module handle");
        let instance = HINSTANCE(module.0);
        let class = WNDCLASSW { lpfnWndProc: Some(window_proc), hInstance: instance, lpszClassName: w!("TohKlasCaptureSpike"), ..Default::default() };
        RegisterClassW(&class);

        // "Ghost": on screen so the compositor keeps updating it, but 1/255 opaque and
        // click-through, so nobody can see or touch it.
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

// --- the capture side --------------------------------------------------------------

#[derive(Default)]
struct Stats {
    frames: AtomicU64,
    right: AtomicU64,
    wrong: AtomicU64,
    map_nanos: AtomicU64,
    first_frame: Mutex<Option<Instant>>,
    size: Mutex<(u32, u32)>,
    first_pixels: Mutex<Option<[(u8, u8, u8); 2]>>,
    stop: AtomicBool,
}

struct Capturer {
    stats: Arc<Stats>,
    encoder: Option<VideoEncoder>,
}

/// Where to write an H.264 file, and at what size, when `--encode` is given.
type EncodeTarget = Option<(std::path::PathBuf, u32, u32)>;

impl GraphicsCaptureApiHandler for Capturer {
    type Flags = (Arc<Stats>, EncodeTarget);
    type Error = Box<dyn std::error::Error + Send + Sync>;

    fn new(context: Context<Self::Flags>) -> Result<Self, Self::Error> {
        let (stats, target) = context.flags;
        let encoder = match target {
            // 1.5 Mbps H.264 at 30 fps is what a full-screen view might reasonably use.
            Some((path, width, height)) => Some(VideoEncoder::new(
                VideoSettingsBuilder::new(width, height).sub_type(VideoSettingsSubType::H264).bitrate(1_500_000).frame_rate(30),
                AudioSettingsBuilder::default().disabled(true),
                ContainerSettingsBuilder::default(),
                path,
            )?),
            None => None,
        };
        Ok(Self { stats, encoder })
    }

    fn on_frame_arrived(&mut self, frame: &mut Frame, control: InternalCaptureControl) -> Result<(), Self::Error> {
        let started = Instant::now();
        let mut buffer = frame.buffer()?;
        let (width, height, pitch) = (buffer.width(), buffer.height(), buffer.row_pitch() as usize);
        let pixels = buffer.as_raw_buffer();
        self.stats.map_nanos.fetch_add(started.elapsed().as_nanos() as u64, Ordering::Relaxed);

        // BGRA. Compare the two reference blocks with what was painted.
        let at = |x: usize, y: usize| {
            let i = y * pitch + x * 4;
            (pixels[i], pixels[i + 1], pixels[i + 2])
        };
        let (b, g, r) = at(10, 10);
        let (b2, g2, r2) = at(width as usize - 10, height as usize - 10);
        self.stats.first_pixels.lock().unwrap().get_or_insert([(b, g, r), (b2, g2, r2)]);
        let red = r > 245 && g < 10 && b < 10;
        let blue = b2 > 245 && g2 < 10 && r2 < 10;
        if red && blue {
            self.stats.right.fetch_add(1, Ordering::Relaxed);
        } else {
            self.stats.wrong.fetch_add(1, Ordering::Relaxed);
        }

        self.stats.first_frame.lock().unwrap().get_or_insert(started);
        *self.stats.size.lock().unwrap() = (width, height);
        self.stats.frames.fetch_add(1, Ordering::Relaxed);

        if let Some(encoder) = self.encoder.as_mut() {
            encoder.send_frame(frame)?;
        }

        if self.stats.stop.load(Ordering::Relaxed) {
            if let Some(encoder) = self.encoder.take() {
                encoder.finish()?;
            }
            control.stop();
        }
        Ok(())
    }
}

fn process_cpu_seconds() -> f64 {
    let (mut created, mut exited, mut kernel, mut user) = (FILETIME::default(), FILETIME::default(), FILETIME::default(), FILETIME::default());
    unsafe {
        let _ = GetProcessTimes(GetCurrentProcess(), &mut created, &mut exited, &mut kernel, &mut user);
    }
    let ticks = |t: FILETIME| ((t.dwHighDateTime as u64) << 32 | t.dwLowDateTime as u64) as f64;
    (ticks(kernel) + ticks(user)) / 10_000_000.0
}

fn run_case(width: i32, height: i32, ghost: bool, encode: bool) -> bool {
    let (hwnd, window_thread) = spawn_window(width, height, ghost);
    std::thread::sleep(Duration::from_millis(300));

    let stats = Arc::new(Stats::default());
    let video = std::env::temp_dir().join(format!("toh-klas-native-spike-{width}x{height}.mp4"));
    let target: EncodeTarget = encode.then(|| (video.clone(), width as u32, height as u32));
    let settings = Settings::new(
        Window::from_raw_hwnd(hwnd as *mut std::ffi::c_void),
        CursorCaptureSettings::WithoutCursor,
        DrawBorderSettings::WithoutBorder,
        SecondaryWindowSettings::Default,
        MinimumUpdateIntervalSettings::Default,
        DirtyRegionSettings::Default,
        ColorFormat::Bgra8,
        (stats.clone(), target),
    );

    let cpu_before = process_cpu_seconds();
    let wall = Instant::now();
    let control = match Capturer::start_free_threaded(settings) {
        Ok(control) => control,
        Err(error) => {
            println!("{width}x{height}: could not start capture: {error}");
            unsafe { let _ = PostMessageW(Some(HWND(hwnd as *mut _)), WM_CLOSE, WPARAM(0), LPARAM(0)); }
            return false;
        }
    };

    std::thread::sleep(Duration::from_secs(RUN_SECONDS));
    let elapsed = wall.elapsed().as_secs_f64();
    let cpu = process_cpu_seconds() - cpu_before;
    stats.stop.store(true, Ordering::Relaxed);
    let _ = control.stop();

    unsafe { let _ = PostMessageW(Some(HWND(hwnd as *mut _)), WM_CLOSE, WPARAM(0), LPARAM(0)); }
    unsafe { let _ = windows::Win32::UI::WindowsAndMessaging::DestroyWindow(HWND(hwnd as *mut _)); }
    let _ = window_thread.join();

    let frames = stats.frames.load(Ordering::Relaxed);
    let (right, wrong) = (stats.right.load(Ordering::Relaxed), stats.wrong.load(Ordering::Relaxed));
    let size = *stats.size.lock().unwrap();
    let map_ms = if frames > 0 { stats.map_nanos.load(Ordering::Relaxed) as f64 / frames as f64 / 1e6 } else { 0.0 };
    let first = stats.first_frame.lock().unwrap().map(|t| t.duration_since(wall).as_millis());

    println!("  first frame pixels (B,G,R) top-left / bottom-right: {:?}", stats.first_pixels.lock().unwrap());
    println!(
        "{width}x{height}: {} frames in {elapsed:.1}s = {:.1} fps | captured size {}x{} | correct pixels {right}/{} | copy to CPU {map_ms:.2} ms/frame | process CPU {:.0}% of one core | first frame after {:?} ms",
        frames,
        frames as f64 / elapsed,
        size.0,
        size.1,
        right + wrong,
        cpu / elapsed * 100.0,
        first,
    );

    if encode {
        let bytes = std::fs::metadata(&video).map(|m| m.len()).unwrap_or(0);
        let header = std::fs::read(&video).unwrap_or_default();
        let is_mp4 = header.get(4..8) == Some(b"ftyp");
        println!("  H.264 file: {bytes} bytes over {elapsed:.1}s = {:.0} kbps, valid MP4 header: {is_mp4}", bytes as f64 * 8.0 / elapsed / 1000.0);
        let _ = std::fs::remove_file(&video);
        if !is_mp4 || bytes == 0 {
            return false;
        }
    }

    frames > 30 && wrong == 0 && size == (width as u32, height as u32)
}

fn main() {
    // Nothing here may hang: if anything stalls, exit rather than leave a process behind.
    std::thread::spawn(|| {
        std::thread::sleep(Duration::from_secs(60));
        eprintln!("watchdog: exiting after 60s");
        std::process::exit(2);
    });

    let ghost = std::env::args().any(|a| a == "--ghost");
    let encode = std::env::args().any(|a| a == "--encode");
    println!("Windows Graphics Capture from Rust, capturing our own {} window\n", if ghost { "near-invisible on-screen" } else { "off-screen" });
    let mut passed = true;
    for (width, height) in [(1280, 720), (1920, 1080)] {
        passed &= run_case(width, height, ghost, encode);
    }
    println!("\n{}", if passed { "PASS" } else { "FAIL" });
    std::process::exit(if passed { 0 } else { 1 });
}
