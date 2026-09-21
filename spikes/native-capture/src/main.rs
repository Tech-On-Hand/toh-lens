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

use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use native_capture_spike::mf;
use native_capture_spike::window::spawn_window;
use windows::Win32::Foundation::{FILETIME, HWND, LPARAM, WPARAM};
use windows::Win32::System::Threading::{GetCurrentProcess, GetProcessTimes};
use windows::Win32::UI::WindowsAndMessaging::{PostMessageW, WM_CLOSE};
use windows_capture::capture::{Context, GraphicsCaptureApiHandler};
use windows_capture::encoder::{AudioSettingsBuilder, ContainerSettingsBuilder, VideoEncoder, VideoSettingsBuilder, VideoSettingsSubType};
use windows_capture::frame::Frame;
use windows_capture::graphics_capture_api::InternalCaptureControl;
use windows_capture::settings::{
    ColorFormat, CursorCaptureSettings, DirtyRegionSettings, DrawBorderSettings, MinimumUpdateIntervalSettings, SecondaryWindowSettings,
    Settings,
};
use windows_capture::window::Window;

const RUN_SECONDS: u64 = 5;

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

// --- raw H.264 bitstream mode -------------------------------------------------------
//
// Drives Windows' encoder directly (see mf.rs) instead of the file-writing helper in
// `windows-capture`, and writes the access units WebRTC would send: length-prefixed,
// with a key-frame flag, in `RawFrameFile`. Also exercises the two controls adaptive
// quality needs: forcing a keyframe on demand and changing the bitrate mid-stream.

const RAW_RUN_SECONDS: f64 = 7.0;
const RAW_FPS: u32 = 30;
const RAW_INITIAL_BITRATE: u32 = 1_500_000;
const RAW_REDUCED_BITRATE: u32 = 300_000;
const KEYFRAME_REQUEST_AT: f64 = 2.5;
const BITRATE_SWITCH_AT: f64 = 4.5;

/// `[u32 len LE][u8 key (0/1)][data...]` per frame, easy for a JS harness to parse.
struct RawFrameFile(std::fs::File);

impl RawFrameFile {
    fn write(&mut self, frame: &mf::EncodedFrame) -> std::io::Result<()> {
        use std::io::Write;
        self.0.write_all(&(frame.data.len() as u32).to_le_bytes())?;
        self.0.write_all(&[u8::from(frame.key)])?;
        self.0.write_all(&frame.data)
    }
}

#[derive(Default)]
struct RawStats {
    frames: AtomicU64,
    keyframes: AtomicU64,
    bytes_before_switch: AtomicU64,
    bytes_total: AtomicU64,
    keyframe_after_request_ms: Mutex<Option<f64>>,
    first_frame_has_parameter_sets: Mutex<Option<bool>>,
}

struct RawCapturer {
    encoder: mf::H264Encoder,
    file: RawFrameFile,
    stats: Arc<RawStats>,
    start: Instant,
    keyframe_requested: bool,
    keyframe_request_time: f64,
    bitrate_switched: bool,
}

impl GraphicsCaptureApiHandler for RawCapturer {
    type Flags = (u32, u32, std::path::PathBuf, Arc<RawStats>);
    type Error = Box<dyn std::error::Error + Send + Sync>;

    fn new(context: Context<Self::Flags>) -> Result<Self, Self::Error> {
        let (width, height, path, stats) = context.flags;
        Ok(Self {
            encoder: mf::H264Encoder::new(width, height, RAW_FPS, RAW_INITIAL_BITRATE)?,
            file: RawFrameFile(std::fs::File::create(path)?),
            stats,
            start: Instant::now(),
            keyframe_requested: false,
            keyframe_request_time: 0.0,
            bitrate_switched: false,
        })
    }

    fn on_frame_arrived(&mut self, frame: &mut Frame, control: InternalCaptureControl) -> Result<(), Self::Error> {
        let elapsed = self.start.elapsed().as_secs_f64();

        if !self.keyframe_requested && elapsed >= KEYFRAME_REQUEST_AT {
            self.encoder.force_keyframe()?;
            self.keyframe_requested = true;
            self.keyframe_request_time = elapsed;
        }
        if !self.bitrate_switched && elapsed >= BITRATE_SWITCH_AT {
            self.encoder.set_bitrate(RAW_REDUCED_BITRATE)?;
            self.bitrate_switched = true;
            self.stats.bytes_before_switch.store(self.stats.bytes_total.load(Ordering::Relaxed), Ordering::Relaxed);
        }

        let mut buffer = frame.buffer()?;
        let pitch = buffer.row_pitch() as usize;
        let bgra = buffer.as_raw_buffer();
        let timestamp = (elapsed * 10_000_000.0) as i64;
        let duration = (10_000_000.0 / f64::from(RAW_FPS)) as i64;

        for encoded in self.encoder.encode(bgra, pitch, timestamp, duration)? {
            if self.stats.frames.load(Ordering::Relaxed) == 0 {
                *self.stats.first_frame_has_parameter_sets.lock().unwrap() = Some(encoded.has_parameter_sets);
            }
            if encoded.key {
                self.stats.keyframes.fetch_add(1, Ordering::Relaxed);
                // The very first frame is always a keyframe; only the later, requested one counts.
                if self.keyframe_requested && elapsed >= self.keyframe_request_time && self.stats.keyframe_after_request_ms.lock().unwrap().is_none() {
                    *self.stats.keyframe_after_request_ms.lock().unwrap() = Some((elapsed - self.keyframe_request_time) * 1000.0);
                }
            }
            self.stats.bytes_total.fetch_add(encoded.data.len() as u64, Ordering::Relaxed);
            self.file.write(&encoded)?;
            self.stats.frames.fetch_add(1, Ordering::Relaxed);
        }

        if elapsed >= RAW_RUN_SECONDS {
            control.stop();
        }
        Ok(())
    }
}

fn run_raw_case(width: i32, height: i32) -> bool {
    let (hwnd, window_thread) = spawn_window(width, height, true);
    std::thread::sleep(Duration::from_millis(300));

    let stats = Arc::new(RawStats::default());
    let path = std::env::temp_dir().join(format!("toh-klas-raw-spike-{width}x{height}.h264frames"));
    let settings = Settings::new(
        Window::from_raw_hwnd(hwnd as *mut std::ffi::c_void),
        CursorCaptureSettings::WithoutCursor,
        DrawBorderSettings::WithoutBorder,
        SecondaryWindowSettings::Default,
        MinimumUpdateIntervalSettings::Default,
        DirtyRegionSettings::Default,
        ColorFormat::Bgra8,
        (width as u32, height as u32, path.clone(), stats.clone()),
    );

    let control = match RawCapturer::start_free_threaded(settings) {
        Ok(control) => control,
        Err(error) => {
            println!("raw {width}x{height}: could not start: {error}");
            unsafe { let _ = PostMessageW(Some(HWND(hwnd as *mut _)), WM_CLOSE, WPARAM(0), LPARAM(0)); }
            return false;
        }
    };

    std::thread::sleep(Duration::from_secs_f64(RAW_RUN_SECONDS + 1.0));
    let _ = control.stop();
    unsafe { let _ = PostMessageW(Some(HWND(hwnd as *mut _)), WM_CLOSE, WPARAM(0), LPARAM(0)); }
    unsafe { let _ = windows::Win32::UI::WindowsAndMessaging::DestroyWindow(HWND(hwnd as *mut _)); }
    let _ = window_thread.join();

    let frames = stats.frames.load(Ordering::Relaxed);
    let keyframes = stats.keyframes.load(Ordering::Relaxed);
    let bytes_total = stats.bytes_total.load(Ordering::Relaxed);
    let bytes_before = stats.bytes_before_switch.load(Ordering::Relaxed);
    let before_kbps = bytes_before as f64 * 8.0 / KEYFRAME_REQUEST_AT.max(BITRATE_SWITCH_AT) / 1000.0;
    let after_seconds = RAW_RUN_SECONDS - BITRATE_SWITCH_AT;
    let after_kbps = (bytes_total - bytes_before) as f64 * 8.0 / after_seconds / 1000.0;
    let first_has_params = stats.first_frame_has_parameter_sets.lock().unwrap().unwrap_or(false);
    let keyframe_latency = *stats.keyframe_after_request_ms.lock().unwrap();

    println!(
        "raw {width}x{height}: {frames} access units, {keyframes} keyframes, first frame has SPS/PPS: {first_has_params}",
    );
    println!(
        "  bitrate before switch (target {} kbps): {:.0} kbps | after dropping to {} kbps: {:.0} kbps",
        RAW_INITIAL_BITRATE / 1000,
        before_kbps,
        RAW_REDUCED_BITRATE / 1000,
        after_kbps,
    );
    println!("  requested keyframe at {KEYFRAME_REQUEST_AT}s, one appeared: {keyframe_latency:?} ms later");
    println!("  frame container written to: {}", path.display());

    frames > 60
        && keyframes >= 2
        && first_has_params
        && keyframe_latency.is_some_and(|ms| ms < 500.0)
        && after_kbps < before_kbps * 0.6
}

fn main() {
    // Nothing here may hang: if anything stalls, exit rather than leave a process behind.
    std::thread::spawn(|| {
        std::thread::sleep(Duration::from_secs(60));
        eprintln!("watchdog: exiting after 60s");
        std::process::exit(2);
    });

    if std::env::args().any(|a| a == "--raw") {
        let ok = run_raw_case(1280, 720);
        println!("\n{}", if ok { "PASS" } else { "FAIL" });
        std::process::exit(if ok { 0 } else { 1 });
    }

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
