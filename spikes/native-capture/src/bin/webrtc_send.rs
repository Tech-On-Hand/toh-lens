//! Spike: does a raw H.264 stream, encoded from a real WGC capture, actually reach a
//! browser peer over a direct WebRTC connection (no STUN/TURN server, matching the
//! "direct where possible" design), and get shown on a `<video>` element?
//!
//! This is a plain console binary (no Tauri window): it captures its OWN off-to-the-side
//! window (see `window.rs`), so nothing on the real desktop is captured, exits after a
//! fixed run, and has a watchdog. A companion Node script (`webrtc.mjs`) plays the
//! Teacher side and relays SDP/ICE signaling that would otherwise go through Reverb.

use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
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
use webrtc::rtp_transceiver::rtp_codec::RTCRtpCodecCapability;
use webrtc::track::track_local::track_local_static_sample::TrackLocalStaticSample;
use windows::Win32::Foundation::HWND;
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
const BITRATE: u32 = 1_500_000;
const RUN_SECONDS: f64 = 10.0;

// --- signaling: a thin HTTP client for the Node relay -------------------------------

#[derive(Serialize)]
#[serde(tag = "kind")]
enum ToBrowser<'a> {
    Answer { sdp: &'a RTCSessionDescription },
    Candidate { candidate: &'a webrtc::ice_transport::ice_candidate::RTCIceCandidateInit },
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

    /// Long-polls the relay; returns messages posted by the browser since `since`.
    async fn poll(&self, since: &mut u64) -> Vec<FromBrowser> {
        loop {
            let Ok(response) = self.http.get(format!("{}/from-browser?since={since}", self.base)).send().await else {
                tokio::time::sleep(Duration::from_millis(200)).await;
                continue;
            };
            let Ok(body) = response.json::<serde_json::Value>().await else { continue };
            let messages = body["messages"].as_array().cloned().unwrap_or_default();
            if messages.is_empty() {
                tokio::time::sleep(Duration::from_millis(100)).await;
                continue;
            }
            let mut out = Vec::new();
            for entry in messages {
                *since = (*since).max(entry["n"].as_u64().unwrap_or(0));
                if let Ok(parsed) = serde_json::from_value(entry["data"].clone()) {
                    out.push(parsed);
                }
            }
            return out;
        }
    }
}

// --- capture -> encode -> RTP -------------------------------------------------------

struct SendCapturer {
    encoder: mf::H264Encoder,
    track: Arc<TrackLocalStaticSample>,
    runtime: tokio::runtime::Handle,
    start: Instant,
    frames_sent: Arc<AtomicU64>,
    bytes_sent: Arc<AtomicU64>,
}

impl GraphicsCaptureApiHandler for SendCapturer {
    type Flags = (Arc<TrackLocalStaticSample>, tokio::runtime::Handle, Arc<AtomicU64>, Arc<AtomicU64>);
    type Error = Box<dyn std::error::Error + Send + Sync>;

    fn new(context: Context<Self::Flags>) -> Result<Self, Self::Error> {
        let (track, runtime, frames_sent, bytes_sent) = context.flags;
        Ok(Self {
            encoder: mf::H264Encoder::new(WIDTH as u32, HEIGHT as u32, FPS, BITRATE)?,
            track,
            runtime,
            start: Instant::now(),
            frames_sent,
            bytes_sent,
        })
    }

    fn on_frame_arrived(&mut self, frame: &mut Frame, control: InternalCaptureControl) -> Result<(), Self::Error> {
        let elapsed = self.start.elapsed().as_secs_f64();
        let mut buffer = frame.buffer()?;
        let pitch = buffer.row_pitch() as usize;
        let bgra = buffer.as_raw_buffer();
        let timestamp = (elapsed * 10_000_000.0) as i64;
        let duration_100ns = (10_000_000.0 / f64::from(FPS)) as i64;

        for encoded in self.encoder.encode(bgra, pitch, timestamp, duration_100ns)? {
            self.bytes_sent.fetch_add(encoded.data.len() as u64, Ordering::Relaxed);
            self.frames_sent.fetch_add(1, Ordering::Relaxed);
            let track = self.track.clone();
            let sample = Sample {
                data: encoded.data.into(),
                duration: Duration::from_secs_f64(1.0 / f64::from(FPS)),
                timestamp: SystemTime::now(),
                ..Default::default()
            };
            // write_sample is async; on_frame_arrived is sync, so hand it to the runtime
            // rather than block the capture thread on network/lock waits.
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
        std::thread::sleep(Duration::from_secs(45));
        eprintln!("watchdog: exiting after 45s");
        std::process::exit(2);
    });

    let base = std::env::args().nth(1).unwrap_or_else(|| "http://127.0.0.1:0".to_string());
    let signaling = Signaling { http: reqwest::Client::new(), base };

    let mut media_engine = MediaEngine::default();
    media_engine.register_default_codecs().expect("register codecs");
    let mut registry = Registry::new();
    registry = register_default_interceptors(registry, &mut media_engine).expect("register interceptors");
    let api = APIBuilder::new().with_media_engine(media_engine).with_interceptor_registry(registry).build();

    // Empty ice_servers: no STUN/TURN. This is the direct-connection path the
    // architecture treats as the common case; the spike-only relay above stands in
    // for what would be Reverb-based signaling in the real product.
    let peer_connection = Arc::new(api.new_peer_connection(RTCConfiguration::default()).await.expect("peer connection"));

    let track = Arc::new(TrackLocalStaticSample::new(
        RTCRtpCodecCapability { mime_type: MIME_TYPE_H264.to_owned(), clock_rate: 90000, ..Default::default() },
        "video".to_owned(),
        "toh-klas-spike".to_owned(),
    ));
    let rtp_sender = peer_connection.add_track(track.clone()).await.expect("add track");
    tokio::spawn(async move {
        let mut buf = vec![0u8; 1500];
        while rtp_sender.read(&mut buf).await.is_ok() {}
    });

    let pending_candidates: Arc<Mutex<Vec<RTCIceCandidateInit>>> = Arc::new(Mutex::new(Vec::new()));
    let remote_set = Arc::new(AtomicBool::new(false));

    {
        let signaling = Arc::new(Signaling { http: reqwest::Client::new(), base: signaling.base.clone() });
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

    let connected = Arc::new(tokio::sync::Notify::new());
    let final_state = Arc::new(Mutex::new(RTCPeerConnectionState::Unspecified));
    {
        let connected = connected.clone();
        let final_state = final_state.clone();
        peer_connection.on_peer_connection_state_change(Box::new(move |state| {
            println!("[rust] connection state: {state}");
            *final_state.lock().unwrap() = state;
            if state == RTCPeerConnectionState::Connected {
                connected.notify_waiters();
            }
            Box::pin(async {})
        }));
    }

    // --- signaling handshake ---
    let mut since = 0u64;
    loop {
        for message in signaling.poll(&mut since).await {
            match message {
                FromBrowser::Offer { sdp } => {
                    println!("[rust] got offer, answering");
                    peer_connection.set_remote_description(sdp).await.expect("set remote description");
                    remote_set.store(true, Ordering::SeqCst);
                    for candidate in pending_candidates.lock().unwrap().drain(..) {
                        let _ = peer_connection.add_ice_candidate(candidate).await;
                    }
                    let answer = peer_connection.create_answer(None).await.expect("create answer");
                    peer_connection.set_local_description(answer.clone()).await.expect("set local description");
                    signaling.send(ToBrowser::Answer { sdp: &answer }).await;
                }
                FromBrowser::Candidate { candidate } => {
                    if remote_set.load(Ordering::SeqCst) {
                        let _ = peer_connection.add_ice_candidate(candidate).await;
                    } else {
                        pending_candidates.lock().unwrap().push(candidate);
                    }
                }
            }
        }
        if remote_set.load(Ordering::SeqCst) {
            break;
        }
    }

    println!("[rust] waiting for the connection to establish...");
    let timed_out = tokio::time::timeout(Duration::from_secs(15), connected.notified()).await.is_err();
    if timed_out {
        println!("[rust] FAILED: never reached the Connected state (last seen: {:?})", *final_state.lock().unwrap());
        std::process::exit(1);
    }
    println!("[rust] connected, starting capture");

    let frames_sent = Arc::new(AtomicU64::new(0));
    let bytes_sent = Arc::new(AtomicU64::new(0));
    let (hwnd, window_thread) = spawn_window(WIDTH, HEIGHT, true);
    std::thread::sleep(Duration::from_millis(300));

    let settings = Settings::new(
        Window::from_raw_hwnd(hwnd as *mut std::ffi::c_void),
        CursorCaptureSettings::WithoutCursor,
        DrawBorderSettings::WithoutBorder,
        SecondaryWindowSettings::Default,
        MinimumUpdateIntervalSettings::Default,
        DirtyRegionSettings::Default,
        ColorFormat::Bgra8,
        (track.clone(), tokio::runtime::Handle::current(), frames_sent.clone(), bytes_sent.clone()),
    );
    let capture = SendCapturer::start_free_threaded(settings).expect("start capture");

    tokio::time::sleep(Duration::from_secs_f64(RUN_SECONDS + 1.0)).await;
    let _ = capture.stop();
    unsafe {
        let _ = PostMessageW(Some(HWND(hwnd as *mut _)), WM_CLOSE, windows::Win32::Foundation::WPARAM(0), windows::Win32::Foundation::LPARAM(0));
        let _ = DestroyWindow(HWND(hwnd as *mut _));
    }
    let _ = window_thread.join();
    tokio::time::sleep(Duration::from_millis(300)).await; // let queued write_sample tasks finish

    let frames = frames_sent.load(Ordering::Relaxed);
    let bytes = bytes_sent.load(Ordering::Relaxed);
    println!(
        "[rust] sent {frames} access units, {bytes} bytes ({:.0} kbps) over {RUN_SECONDS}s, final state: {:?}",
        bytes as f64 * 8.0 / RUN_SECONDS / 1000.0,
        *final_state.lock().unwrap(),
    );

    let stats = json!({ "framesSent": frames, "bytesSent": bytes, "finalState": format!("{:?}", *final_state.lock().unwrap()) });
    let _ = signaling.http.post(format!("{}/rust-stats", signaling.base)).json(&stats).send().await;

    let _ = peer_connection.close().await;
    std::process::exit(if frames > 0 { 0 } else { 1 });
}
