//! Shows this device's screen live on every other kiosk in the classroom —
//! Milestone 4's teacher-broadcast feature. Direct kiosk-to-kiosk connections,
//! one per receiving kiosk, all fed from a single shared capture+encoder (the
//! "one-encode-N-sends" pattern proven in `spikes/native-capture`). Independent
//! of `screen_share.rs`'s regular one-teacher-watch pipeline — its own capture,
//! its own encoder, so the two never interfere with each other's quality.
//!
//! Signaling direction is reversed from screen_sessions: the receiving kiosk is
//! the offerer (the same role a teacher plays watching a device), and this
//! device is always the answerer for its own broadcast's targets (the same
//! role a watched device plays) — structurally the same kind of connection,
//! just device-to-device instead of device-to-teacher. The receiving side of
//! that connection lives in the kiosk's own webview (see `lib/broadcastView.ts`
//! on the frontend), not here — a Rust-side H.264 decoder would be a far bigger
//! undertaking than reusing the browser's native WebRTC, which every kiosk
//! already has.

use crate::state::AppState;
use serde::Deserialize;
use serde_json::Value;
use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant, SystemTime};
use webrtc::ice_transport::ice_candidate::RTCIceCandidateInit;
use webrtc::media::Sample;
use webrtc::peer_connection::sdp::session_description::RTCSessionDescription;
use webrtc::peer_connection::RTCPeerConnection;
use webrtc::rtp_transceiver::rtp_codec::RTCRtpCodecCapability;
use webrtc::api::media_engine::MIME_TYPE_H264;
use webrtc::track::track_local::track_local_static_sample::TrackLocalStaticSample;
use windows_capture::capture::{CaptureControl, Context, GraphicsCaptureApiHandler};
use windows_capture::frame::Frame;
use windows_capture::graphics_capture_api::InternalCaptureControl;
use windows_capture::monitor::Monitor;
use windows_capture::settings::{
    ColorFormat, CursorCaptureSettings, DirtyRegionSettings, DrawBorderSettings, MinimumUpdateIntervalSettings, SecondaryWindowSettings,
    Settings,
};

// Fixed quality: meant to be clearly visible to the whole class on a
// projector/shared screen, not adaptive like the teacher-watch thumbnail/full
// split. Same profile as screen_share.rs's "full" tier, not shared as a
// constant since the two pipelines are deliberately independent.
const WIDTH: u32 = 1280;
const HEIGHT: u32 = 720;
const FPS: u32 = 15;
const BITRATE: u32 = 1_500_000;

#[derive(Debug, Deserialize)]
struct CandidateDto {
    id: i64,
    payload: Value,
}

#[derive(Debug, Deserialize)]
struct TargetDto {
    id: String,
    offer: Value,
    #[serde(default)]
    candidates: Vec<CandidateDto>,
}

#[derive(Debug, Deserialize)]
struct BroadcastRef {
    id: String,
}

#[derive(Debug, Deserialize)]
struct OutgoingDto {
    broadcast: Option<BroadcastRef>,
    #[serde(default)]
    targets: Vec<TargetDto>,
}

type Capture = CaptureControl<BroadcastCapturer, Box<dyn std::error::Error + Send + Sync>>;
type Tracks = Arc<Mutex<Vec<Arc<TrackLocalStaticSample>>>>;

struct TargetConn {
    peer_connection: Arc<RTCPeerConnection>,
    track: Arc<TrackLocalStaticSample>,
    last_candidate_id: i64,
}

struct Active {
    broadcast_id: String,
    tracks: Tracks,
    capture: Option<Capture>,
    targets: HashMap<String, TargetConn>,
}

#[derive(Clone, Default)]
pub struct ScreenBroadcastSource {
    active: Arc<Mutex<Option<Active>>>,
}

struct BroadcastCapturer {
    encoder: crate::mf::H264Encoder,
    tracks: Tracks,
    runtime: tokio::runtime::Handle,
    start: Instant,
}

impl GraphicsCaptureApiHandler for BroadcastCapturer {
    type Flags = (Tracks, tokio::runtime::Handle);
    type Error = Box<dyn std::error::Error + Send + Sync>;

    fn new(context: Context<Self::Flags>) -> Result<Self, Self::Error> {
        let (tracks, runtime) = context.flags;
        Ok(Self { encoder: crate::mf::H264Encoder::new(WIDTH, HEIGHT, FPS, BITRATE)?, tracks, runtime, start: Instant::now() })
    }

    fn on_frame_arrived(&mut self, frame: &mut Frame, _control: InternalCaptureControl) -> Result<(), Self::Error> {
        let elapsed = self.start.elapsed().as_secs_f64();
        let (src_width, src_height) = (frame.width() as usize, frame.height() as usize);
        let mut buffer = frame.buffer()?;
        let pitch = buffer.row_pitch() as usize;
        let bgra = buffer.as_raw_buffer();
        let timestamp = (elapsed * 10_000_000.0) as i64;
        let duration_100ns = (10_000_000.0 / f64::from(FPS)) as i64;

        // One encode, N sends: every currently-joined target gets the same
        // encoded sample, rather than re-encoding once per viewer.
        for encoded in self.encoder.encode(bgra, pitch, src_width, src_height, timestamp, duration_100ns)? {
            let tracks = self.tracks.lock().unwrap().clone();
            for track in tracks {
                let data = encoded.data.clone();
                self.runtime.spawn(async move {
                    let sample =
                        Sample { data: data.into(), duration: Duration::from_secs_f64(1.0 / f64::from(FPS)), timestamp: SystemTime::now(), ..Default::default() };
                    let _ = track.write_sample(&sample).await;
                });
            }
        }
        Ok(())
    }
}

fn start_broadcast_capture(tracks: Tracks) -> Result<Capture, String> {
    let monitor = Monitor::primary().map_err(|e| e.to_string())?;
    let settings = Settings::new(
        monitor,
        CursorCaptureSettings::WithCursor,
        DrawBorderSettings::WithoutBorder,
        SecondaryWindowSettings::Default,
        MinimumUpdateIntervalSettings::Default,
        DirtyRegionSettings::Default,
        ColorFormat::Bgra8,
        (tracks, tokio::runtime::Handle::current()),
    );
    BroadcastCapturer::start_free_threaded(settings).map_err(|e| e.to_string())
}

impl ScreenBroadcastSource {
    async fn teardown(&self) {
        let active = self.active.lock().unwrap().take();
        if let Some(active) = active {
            if let Some(capture) = active.capture {
                let _ = capture.stop();
            }
            for (_, conn) in active.targets {
                let _ = conn.peer_connection.close().await;
            }
        }
    }

    fn begin_broadcast(&self, broadcast_id: &str) {
        let tracks: Tracks = Arc::new(Mutex::new(Vec::new()));
        let capture = start_broadcast_capture(tracks.clone()).ok();
        *self.active.lock().unwrap() = Some(Active { broadcast_id: broadcast_id.to_string(), tracks, capture, targets: HashMap::new() });
    }

    /// Opens a new answerer connection for one receiving kiosk and registers
    /// its track into the shared fan-out list so the capturer starts sending
    /// to it. Mirrors `screen_share.rs`'s `begin`, one level removed: N of
    /// these can be live at once instead of exactly one.
    async fn begin_target(&self, base_url: &str, token: &str, target: &TargetDto) {
        let offer = match serde_json::from_value::<RTCSessionDescription>(target.offer.clone()) {
            Ok(offer) => offer,
            Err(_) => return,
        };

        let Ok(peer_connection) = crate::screen_share::new_peer_connection().await else { return };
        let track = Arc::new(TrackLocalStaticSample::new(
            RTCRtpCodecCapability { mime_type: MIME_TYPE_H264.to_owned(), clock_rate: 90000, ..Default::default() },
            "video".to_owned(),
            format!("toh-klas-broadcast-{}", target.id),
        ));
        let Ok(rtp_sender) = peer_connection.add_track(track.clone()).await else { return };
        tokio::spawn(async move {
            let mut buf = vec![0u8; 1500];
            while rtp_sender.read(&mut buf).await.is_ok() {}
        });

        {
            let (base_url, token, target_id) = (base_url.to_string(), token.to_string(), target.id.clone());
            let http = reqwest::Client::new();
            peer_connection.on_ice_candidate(Box::new(move |candidate| {
                let (http, base_url, token, target_id) = (http.clone(), base_url.clone(), token.clone(), target_id.clone());
                Box::pin(async move {
                    let Some(candidate) = candidate else { return };
                    let Ok(init) = candidate.to_json() else { return };
                    let Ok(payload) = serde_json::to_value(init) else { return };
                    let _ = crate::api_client::post_broadcast_target_candidate(&http, &base_url, &token, &target_id, &payload).await;
                })
            }));
        }

        if peer_connection.set_remote_description(offer).await.is_err() {
            let _ = peer_connection.close().await;
            return;
        }
        let Ok(answer) = peer_connection.create_answer(None).await else { return };
        if peer_connection.set_local_description(answer.clone()).await.is_err() {
            let _ = peer_connection.close().await;
            return;
        }

        let Ok(mut answer_json) = serde_json::to_value(&answer) else { return };
        if let Some(sdp) = answer_json.get("sdp").and_then(|v| v.as_str()) {
            answer_json["sdp"] = serde_json::Value::String(crate::screen_share::normalize_sdp_line_endings(sdp));
        }
        let _ = crate::api_client::answer_broadcast_target(&reqwest::Client::new(), base_url, token, &target.id, &answer_json).await;

        let should_close = {
            let mut guard = self.active.lock().unwrap();
            match guard.as_mut() {
                Some(active) => {
                    active.tracks.lock().unwrap().push(track.clone());
                    active.targets.insert(target.id.clone(), TargetConn { peer_connection: peer_connection.clone(), track, last_candidate_id: 0 });
                    false
                }
                // The broadcast ended while this connection was being set up.
                None => true,
            }
        };
        if should_close {
            let _ = peer_connection.close().await;
        }
    }

    async fn apply_target_candidates(&self, target: &TargetDto) {
        let (peer_connection, cursor) = {
            let guard = self.active.lock().unwrap();
            match guard.as_ref().and_then(|a| a.targets.get(&target.id)) {
                Some(conn) => (conn.peer_connection.clone(), conn.last_candidate_id),
                None => return,
            }
        };
        let mut max_id = cursor;
        for candidate in &target.candidates {
            max_id = max_id.max(candidate.id);
            if let Ok(init) = serde_json::from_value::<RTCIceCandidateInit>(candidate.payload.clone()) {
                let _ = peer_connection.add_ice_candidate(init).await;
            }
        }
        if let Some(active) = self.active.lock().unwrap().as_mut() {
            if let Some(conn) = active.targets.get_mut(&target.id) {
                conn.last_candidate_id = max_id;
            }
        }
    }

    async fn remove_target(&self, target_id: &str) {
        let removed = {
            let mut guard = self.active.lock().unwrap();
            guard.as_mut().and_then(|a| a.targets.remove(target_id))
        };
        let Some(conn) = removed else { return };
        let _ = conn.peer_connection.close().await;
        if let Some(active) = self.active.lock().unwrap().as_ref() {
            active.tracks.lock().unwrap().retain(|t| !Arc::ptr_eq(t, &conn.track));
        }
    }

    async fn reconcile_targets(&self, base_url: &str, token: &str, targets: &[TargetDto]) {
        let existing_ids: Vec<String> = {
            let guard = self.active.lock().unwrap();
            guard.as_ref().map(|a| a.targets.keys().cloned().collect()).unwrap_or_default()
        };
        let incoming_ids: Vec<&str> = targets.iter().map(|t| t.id.as_str()).collect();

        for id in existing_ids.iter().filter(|id| !incoming_ids.contains(&id.as_str())) {
            self.remove_target(id).await;
        }

        for target in targets {
            let already_present = { self.active.lock().unwrap().as_ref().is_some_and(|a| a.targets.contains_key(&target.id)) };
            if already_present {
                self.apply_target_candidates(target).await;
            } else {
                self.begin_target(base_url, token, target).await;
            }
        }
    }

    pub async fn tick(&self, state: &AppState) {
        let (base_url, token) = {
            let conn = state.db.lock().unwrap();
            (crate::db::config_repo::get_api_base_url(&conn).ok().flatten(), crate::credential_store::get_token())
        };
        let (Some(base_url), Some(token)) = (base_url, token) else { return };

        let Ok(value) = crate::api_client::fetch_outgoing_broadcast(&state.http, &base_url, &token).await else { return };
        let Ok(outgoing) = serde_json::from_value::<OutgoingDto>(value) else { return };

        match outgoing.broadcast {
            None => self.teardown().await,
            Some(broadcast_ref) => {
                let is_new = { self.active.lock().unwrap().as_ref().map(|a| a.broadcast_id.clone()) != Some(broadcast_ref.id.clone()) };
                if is_new {
                    self.teardown().await;
                    self.begin_broadcast(&broadcast_ref.id);
                }
                self.reconcile_targets(&base_url, &token, &outgoing.targets).await;
            }
        }
    }
}
