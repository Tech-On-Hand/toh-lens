//! Spike: the OTHER direction from `webrtc_send.rs` — a teacher broadcasting their
//! own screen to the whole class, not a teacher viewing student thumbnails.
//!
//! The roadmap risk this checks: "one teacher PC encoding for ~30 students is 30
//! encoder sessions unless we send one stream a different way." The "different way"
//! is `TrackLocalStaticSample` bound to N `RTCPeerConnection`s at once — one capture,
//! one encode, N independent RTP sends (its own doc comment says as much: "the
//! packets will still be sent to all PeerConnections"). This proves that mechanism
//! actually works at a real N, and separates what N buys you (constant CPU/encode
//! cost) from what it can't (network egress, which is inherently N times the bitrate,
//! because each viewer needs their own copy of the bytes).
//!
//! Same no-window-on-your-desktop, watchdog-protected setup as `webrtc_send.rs`.

use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant, SystemTime};

use native_capture_spike::mf;
use native_capture_spike::window::spawn_window;
use serde::{Deserialize, Serialize};
use serde_json::json;
use webrtc::api::interceptor_registry::register_default_interceptors;
use webrtc::api::media_engine::{MediaEngine, MIME_TYPE_H264};
use webrtc::api::APIBuilder;
use webrtc::ice_transport::ice_candidate::RTCIceCandidateInit;
use webrtc::interceptor::registry::Registry;
use webrtc::media::Sample;
use webrtc::peer_connection::configuration::RTCConfiguration;
use webrtc::peer_connection::peer_connection_state::RTCPeerConnectionState;
use webrtc::peer_connection::sdp::session_description::RTCSessionDescription;
use webrtc::peer_connection::RTCPeerConnection;
use webrtc::rtp_transceiver::rtp_codec::RTCRtpCodecCapability;
use webrtc::track::track_local::track_local_static_sample::TrackLocalStaticSample;
use windows::Win32::Foundation::{HWND, LPARAM, WPARAM};
use windows::Win32::UI::WindowsAndMessaging::{DestroyWindow, PostMessageW, WM_CLOSE};
use windows_capture::capture::{Context, GraphicsCaptureApiHandler};
use windows_capture::frame::Frame;
use windows_capture::graphics_capture_api::InternalCaptureControl;
use windows_capture::settings::{
    ColorFormat, CursorCaptureSettings, DirtyRegionSettings, DrawBorderSettings, MinimumUpdateIntervalSettings, SecondaryWindowSettings,
    Settings,
};
use windows_capture::window::Window;

const WIDTH: i32 = 1280;
const HEIGHT: i32 = 720;
const FPS: u32 = 30;
const BITRATE: u32 = 1_500_000; // "the teacher's own screen", so full quality, not a thumbnail.
const RUN_SECONDS: f64 = 8.0;

#[derive(Serialize)]
#[serde(tag = "kind")]
enum ToBrowser<'a> {
    Answer { sdp: &'a RTCSessionDescription },
    Candidate { candidate: &'a RTCIceCandidateInit },
}

#[derive(Deserialize, Debug)]
#[serde(tag = "kind")]
enum FromBrowser {
    Offer { sdp: RTCSessionDescription },
    Candidate { candidate: RTCIceCandidateInit },
}

struct Signaling {
    http: reqwest::Client,
    base: String,
}

impl Signaling {
    async fn send(&self, message: ToBrowser<'_>) {
        let _ = self.http.post(format!("{}/to-browser", self.base)).json(&message).send().await;
    }

    async fn poll_once(&self, since: &mut u64) -> Vec<FromBrowser> {
        let Ok(response) = self.http.get(format!("{}/from-browser?since={since}", self.base)).send().await else { return Vec::new() };
        let Ok(body) = response.json::<serde_json::Value>().await else { return Vec::new() };
        let mut out = Vec::new();
        for entry in body["messages"].as_array().cloned().unwrap_or_default() {
            *since = (*since).max(entry["n"].as_u64().unwrap_or(0));
            if let Ok(parsed) = serde_json::from_value(entry["data"].clone()) {
                out.push(parsed);
            }
        }
        out
    }
}

/// Runs one viewer's full signaling handshake against its own path-scoped relay
/// endpoint (`{base}/conn/{id}`), sharing the one `track` all viewers are bound to.
async fn connect_one(
    id: usize,
    base: String,
    peer_connection: Arc<RTCPeerConnection>,
    states: Arc<Mutex<HashMap<usize, RTCPeerConnectionState>>>,
    notify: Arc<tokio::sync::Notify>,
) {
    let signaling = Arc::new(Signaling { http: reqwest::Client::new(), base: format!("{base}/conn/{id}") });

    {
        let signaling = signaling.clone();
        peer_connection.on_ice_candidate(Box::new(move |candidate| {
            let signaling = signaling.clone();
            Box::pin(async move {
                if let Some(candidate) = candidate {
                    if let Ok(init) = candidate.to_json() {
                        signaling.send(ToBrowser::Candidate { candidate: &init }).await;
                    }
                }
            })
        }));
    }
    {
        let states = states.clone();
        let notify = notify.clone();
        peer_connection.on_peer_connection_state_change(Box::new(move |state| {
            states.lock().unwrap().insert(id, state);
            notify.notify_waiters();
            Box::pin(async {})
        }));
    }

    let pending = Arc::new(Mutex::new(Vec::<RTCIceCandidateInit>::new()));
    let mut since = 0u64;
    let mut answered = false;
    let deadline = Instant::now() + Duration::from_secs(20);
    // Keep polling for trickled candidates after answering too — a browser's ICE
    // gathering routinely continues after the answer goes out.
    while Instant::now() < deadline {
        for message in signaling.poll_once(&mut since).await {
            match message {
                FromBrowser::Offer { sdp } => {
                    let _ = peer_connection.set_remote_description(sdp).await;
                    let drained: Vec<_> = pending.lock().unwrap().drain(..).collect();
                    for candidate in drained {
                        let _ = peer_connection.add_ice_candidate(candidate).await;
                    }
                    if let Ok(answer) = peer_connection.create_answer(None).await {
                        let _ = peer_connection.set_local_description(answer.clone()).await;
                        signaling.send(ToBrowser::Answer { sdp: &answer }).await;
                    }
                    answered = true;
                }
                FromBrowser::Candidate { candidate } => {
                    if answered {
                        let _ = peer_connection.add_ice_candidate(candidate).await;
                    } else {
                        pending.lock().unwrap().push(candidate);
                    }
                }
            }
        }
        if answered && matches!(states.lock().unwrap().get(&id), Some(RTCPeerConnectionState::Connected | RTCPeerConnectionState::Failed | RTCPeerConnectionState::Closed)) {
            break;
        }
        tokio::time::sleep(Duration::from_millis(50)).await;
    }
}

struct SendCapturer {
    encoder: mf::H264Encoder,
    track: Arc<TrackLocalStaticSample>,
    runtime: tokio::runtime::Handle,
    start: Instant,
    frames_sent: Arc<AtomicU64>,
    bytes_encoded: Arc<AtomicU64>,
}

impl GraphicsCaptureApiHandler for SendCapturer {
    type Flags = (Arc<TrackLocalStaticSample>, tokio::runtime::Handle, Arc<AtomicU64>, Arc<AtomicU64>);
    type Error = Box<dyn std::error::Error + Send + Sync>;

    fn new(context: Context<Self::Flags>) -> Result<Self, Self::Error> {
        let (track, runtime, frames_sent, bytes_encoded) = context.flags;
        Ok(Self { encoder: mf::H264Encoder::new(WIDTH as u32, HEIGHT as u32, FPS, BITRATE)?, track, runtime, start: Instant::now(), frames_sent, bytes_encoded })
    }

    fn on_frame_arrived(&mut self, frame: &mut Frame, control: InternalCaptureControl) -> Result<(), Self::Error> {
        let elapsed = self.start.elapsed().as_secs_f64();
        let mut buffer = frame.buffer()?;
        let pitch = buffer.row_pitch() as usize;
        let bgra = buffer.as_raw_buffer();
        let timestamp = (elapsed * 10_000_000.0) as i64;
        let duration_100ns = (10_000_000.0 / f64::from(FPS)) as i64;

        for encoded in self.encoder.encode(bgra, pitch, timestamp, duration_100ns)? {
            self.bytes_encoded.fetch_add(encoded.data.len() as u64, Ordering::Relaxed);
            self.frames_sent.fetch_add(1, Ordering::Relaxed);
            let track = self.track.clone();
            let sample = Sample { data: encoded.data.into(), duration: Duration::from_secs_f64(1.0 / f64::from(FPS)), timestamp: SystemTime::now(), ..Default::default() };
            // One call here reaches every bound PeerConnection: this is the "one encode, N sends" path.
            self.runtime.spawn(async move {
                let _ = track.write_sample(&sample).await;
            });
        }

        if elapsed >= RUN_SECONDS {
            control.stop();
        }
        Ok(())
    }
}

#[tokio::main]
async fn main() {
    std::thread::spawn(|| {
        std::thread::sleep(Duration::from_secs(90));
        eprintln!("watchdog: exiting after 90s");
        std::process::exit(2);
    });

    let mut args = std::env::args().skip(1);
    let base = args.next().unwrap_or_else(|| "http://127.0.0.1:0".to_string());
    let count: usize = args.next().and_then(|s| s.parse().ok()).unwrap_or(30);

    let mut media_engine = MediaEngine::default();
    media_engine.register_default_codecs().expect("register codecs");
    let mut registry = Registry::new();
    registry = register_default_interceptors(registry, &mut media_engine).expect("register interceptors");
    let api = APIBuilder::new().with_media_engine(media_engine).with_interceptor_registry(registry).build();

    let track = Arc::new(TrackLocalStaticSample::new(
        RTCRtpCodecCapability { mime_type: MIME_TYPE_H264.to_owned(), clock_rate: 90000, ..Default::default() },
        "video".to_owned(),
        "toh-klas-broadcast-spike".to_owned(),
    ));

    let states = Arc::new(Mutex::new(HashMap::<usize, RTCPeerConnectionState>::new()));
    let notify = Arc::new(tokio::sync::Notify::new());

    println!("[rust] creating {count} peer connections, all sharing one track (no STUN/TURN)");
    let mut handshakes = Vec::new();
    for id in 0..count {
        let peer_connection = Arc::new(api.new_peer_connection(RTCConfiguration::default()).await.expect("peer connection"));
        let rtp_sender = peer_connection.add_track(track.clone()).await.expect("add track");
        tokio::spawn(async move {
            let mut buf = vec![0u8; 1500];
            while rtp_sender.read(&mut buf).await.is_ok() {}
        });
        handshakes.push(tokio::spawn(connect_one(id, base.clone(), peer_connection, states.clone(), notify.clone())));
    }
    for handshake in handshakes {
        let _ = handshake.await;
    }

    println!("[rust] waiting for connections to establish...");
    let deadline = Instant::now() + Duration::from_secs(20);
    loop {
        let connected = states.lock().unwrap().values().filter(|s| **s == RTCPeerConnectionState::Connected).count();
        let settled = states.lock().unwrap().values().filter(|s| matches!(s, RTCPeerConnectionState::Connected | RTCPeerConnectionState::Failed | RTCPeerConnectionState::Closed)).count();
        if connected == count || settled == count || Instant::now() >= deadline {
            println!("[rust] {connected}/{count} connected after handshake phase");
            break;
        }
        let _ = tokio::time::timeout(Duration::from_millis(500), notify.notified()).await;
    }

    let frames_sent = Arc::new(AtomicU64::new(0));
    let bytes_encoded = Arc::new(AtomicU64::new(0));
    let (hwnd, window_thread) = spawn_window(WIDTH, HEIGHT, true);
    std::thread::sleep(Duration::from_millis(300));

    println!("[rust] starting the ONE capture+encode loop, feeding the shared track");
    let capture_started = Instant::now();
    let settings = Settings::new(
        Window::from_raw_hwnd(hwnd as *mut std::ffi::c_void),
        CursorCaptureSettings::WithoutCursor,
        DrawBorderSettings::WithoutBorder,
        SecondaryWindowSettings::Default,
        MinimumUpdateIntervalSettings::Default,
        DirtyRegionSettings::Default,
        ColorFormat::Bgra8,
        (track.clone(), tokio::runtime::Handle::current(), frames_sent.clone(), bytes_encoded.clone()),
    );
    let capture = SendCapturer::start_free_threaded(settings).expect("start capture");

    // Sample this process's own CPU time across the capture window, the same way the
    // native-capture spike measures the cost of ONE encoder — the point being that this
    // number should look like one encoder's cost, not count*one encoder's cost.
    let cpu_before = process_cpu_seconds();
    tokio::time::sleep(Duration::from_secs_f64(RUN_SECONDS + 1.0)).await;
    let cpu_seconds = process_cpu_seconds() - cpu_before;
    let wall = capture_started.elapsed().as_secs_f64();

    let _ = capture.stop();
    unsafe {
        let _ = PostMessageW(Some(HWND(hwnd as *mut _)), WM_CLOSE, WPARAM(0), LPARAM(0));
        let _ = DestroyWindow(HWND(hwnd as *mut _));
    }
    let _ = window_thread.join();
    tokio::time::sleep(Duration::from_millis(500)).await;

    let connected = states.lock().unwrap().values().filter(|s| **s == RTCPeerConnectionState::Connected).count();
    let frames = frames_sent.load(Ordering::Relaxed);
    let encoded_bytes = bytes_encoded.load(Ordering::Relaxed);
    println!(
        "[rust] final: {connected}/{count} still connected | {frames} access units encoded ONCE ({:.0} kbps) | process CPU during capture: {:.0}% of one core",
        encoded_bytes as f64 * 8.0 / wall / 1000.0,
        cpu_seconds / wall * 100.0,
    );
    println!(
        "[rust] theoretical egress if every connection needs its own copy: {:.0} kbps * {connected} viewers = {:.1} Mbps aggregate",
        encoded_bytes as f64 * 8.0 / wall / 1000.0,
        encoded_bytes as f64 * 8.0 / wall / 1000.0 * connected as f64 / 1000.0,
    );

    let stats = json!({
        "connected": connected, "count": count, "framesEncoded": frames, "encodedBytes": encoded_bytes,
        "wallSeconds": wall, "cpuSeconds": cpu_seconds,
    });
    let http = reqwest::Client::new();
    let _ = http.post(format!("{base}/rust-stats")).json(&stats).send().await;

    std::process::exit(if connected == count { 0 } else { 1 });
}

fn process_cpu_seconds() -> f64 {
    use windows::Win32::Foundation::FILETIME;
    use windows::Win32::System::Threading::{GetCurrentProcess, GetProcessTimes};
    let (mut created, mut exited, mut kernel, mut user) = (FILETIME::default(), FILETIME::default(), FILETIME::default(), FILETIME::default());
    unsafe {
        let _ = GetProcessTimes(GetCurrentProcess(), &mut created, &mut exited, &mut kernel, &mut user);
    }
    let ticks = |t: FILETIME| ((t.dwHighDateTime as u64) << 32 | t.dwLowDateTime as u64) as f64;
    (ticks(kernel) + ticks(user)) / 10_000_000.0
}
