//! Streams this computer's screen to a watching teacher over WebRTC, thumbnail
//! quality only. Ported from the proven `spikes/native-capture` webrtc_send spike,
//! wired to the real signaling API (`api_client::*_screen_session*`) instead of a
//! local relay, and capturing the real primary monitor instead of a test window.
//!
//! The device has no live socket (see `browser_bridge.rs` and `policy_sync.rs` for
//! the same choice); this module is driven by `tick`, called on the same cadence as
//! everything else that polls.

use crate::state::AppState;
use serde::Deserialize;
use serde_json::Value;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant, SystemTime};
use webrtc::api::interceptor_registry::register_default_interceptors;
use webrtc::api::media_engine::{MediaEngine, MIME_TYPE_H264};
use webrtc::api::APIBuilder;
use webrtc::ice_transport::ice_candidate::RTCIceCandidateInit;
use webrtc::interceptor::registry::Registry;
use webrtc::media::Sample;
use webrtc::peer_connection::configuration::RTCConfiguration;
use webrtc::peer_connection::sdp::session_description::RTCSessionDescription;
use webrtc::peer_connection::RTCPeerConnection;
use webrtc::rtp_transceiver::rtp_codec::RTCRtpCodecCapability;
use webrtc::track::track_local::track_local_static_sample::TrackLocalStaticSample;
use windows_capture::capture::{CaptureControl, Context, GraphicsCaptureApiHandler};
use windows_capture::frame::Frame;
use windows_capture::graphics_capture_api::InternalCaptureControl;
use windows_capture::monitor::Monitor;
use windows_capture::settings::{
    ColorFormat, CursorCaptureSettings, DirtyRegionSettings, DrawBorderSettings, MinimumUpdateIntervalSettings, SecondaryWindowSettings,
    Settings,
};

/// The "see what everyone is doing" grid view: deliberately small.
const THUMB: EncodeTarget = EncodeTarget { width: 320, height: 180, fps: 5, bitrate: 150_000 };
/// A closer look at one device, requested on demand. Capped well under the
/// monitor's native resolution on purpose — this is still a direct P2P
/// connection with no TURN relay, on what might be a shared classroom network.
const FULL: EncodeTarget = EncodeTarget { width: 1280, height: 720, fps: 15, bitrate: 1_500_000 };

#[derive(Debug, Clone, Copy)]
struct EncodeTarget {
    width: u32,
    height: u32,
    fps: u32,
    bitrate: u32,
}

fn target_for(quality: &str) -> EncodeTarget {
    if quality == "full" { FULL } else { THUMB }
}

fn default_quality() -> String {
    "thumb".to_string()
}

#[derive(Debug, Deserialize)]
struct CandidateDto {
    id: i64,
    payload: Value,
}

#[derive(Debug, Deserialize)]
struct SessionDto {
    id: String,
    offer: Value,
    #[serde(default = "default_quality")]
    quality: String,
    #[serde(default)]
    candidates: Vec<CandidateDto>,
}

type Capture = CaptureControl<ScreenCapturer, Box<dyn std::error::Error + Send + Sync>>;

struct Watch {
    session_id: String,
    peer_connection: Arc<RTCPeerConnection>,
    track: Arc<TrackLocalStaticSample>,
    capture: Option<Capture>,
    last_candidate_id: i64,
    quality: String,
}

#[derive(Clone, Default)]
pub struct ScreenShare {
    watch: Arc<Mutex<Option<Watch>>>,
}

struct ScreenCapturer {
    encoder: crate::mf::H264Encoder,
    track: Arc<TrackLocalStaticSample>,
    runtime: tokio::runtime::Handle,
    start: Instant,
    fps: u32,
}

impl GraphicsCaptureApiHandler for ScreenCapturer {
    type Flags = (Arc<TrackLocalStaticSample>, tokio::runtime::Handle, EncodeTarget);
    type Error = Box<dyn std::error::Error + Send + Sync>;

    fn new(context: Context<Self::Flags>) -> Result<Self, Self::Error> {
        let (track, runtime, target) = context.flags;
        Ok(Self {
            encoder: crate::mf::H264Encoder::new(target.width, target.height, target.fps, target.bitrate)?,
            track,
            runtime,
            start: Instant::now(),
            fps: target.fps,
        })
    }

    fn on_frame_arrived(&mut self, frame: &mut Frame, _control: InternalCaptureControl) -> Result<(), Self::Error> {
        let elapsed = self.start.elapsed().as_secs_f64();
        let (src_width, src_height) = (frame.width() as usize, frame.height() as usize);
        let mut buffer = frame.buffer()?;
        let pitch = buffer.row_pitch() as usize;
        let bgra = buffer.as_raw_buffer();
        let timestamp = (elapsed * 10_000_000.0) as i64;
        let duration_100ns = (10_000_000.0 / f64::from(self.fps)) as i64;

        // Frames written before the connection is up are silently dropped by the
        // track (nothing is bound to send them to yet); that is fine, the next
        // keyframe interval catches up.
        for encoded in self.encoder.encode(bgra, pitch, src_width, src_height, timestamp, duration_100ns)? {
            let track = self.track.clone();
            let sample = Sample {
                data: encoded.data.into(),
                duration: Duration::from_secs_f64(1.0 / f64::from(self.fps)),
                timestamp: SystemTime::now(),
                ..Default::default()
            };
            self.runtime.spawn(async move {
                let _ = track.write_sample(&sample).await;
            });
        }
        Ok(())
    }
}

async fn new_peer_connection() -> Result<Arc<RTCPeerConnection>, String> {
    let mut media_engine = MediaEngine::default();
    media_engine.register_default_codecs().map_err(|e| e.to_string())?;
    let mut registry = Registry::new();
    registry = register_default_interceptors(registry, &mut media_engine).map_err(|e| e.to_string())?;
    let api = APIBuilder::new().with_media_engine(media_engine).with_interceptor_registry(registry).build();

    // Empty ice_servers: no STUN/TURN. Proven to connect directly on a shared
    // classroom LAN in spikes/native-capture; a network that needs a relay is a
    // known, documented gap (see toh-klas-contracts.md), not a silent failure mode —
    // the connection simply never reaches `connected` and the teacher sees "offline".
    api.new_peer_connection(RTCConfiguration::default()).await.map(Arc::new).map_err(|e| e.to_string())
}

fn start_capture(track: Arc<TrackLocalStaticSample>, target: EncodeTarget) -> Result<Capture, String> {
    let monitor = Monitor::primary().map_err(|e| e.to_string())?;
    let settings = Settings::new(
        monitor,
        CursorCaptureSettings::WithCursor,
        DrawBorderSettings::WithoutBorder,
        SecondaryWindowSettings::Default,
        MinimumUpdateIntervalSettings::Default,
        DirtyRegionSettings::Default,
        ColorFormat::Bgra8,
        (track, tokio::runtime::Handle::current(), target),
    );
    ScreenCapturer::start_free_threaded(settings).map_err(|e| e.to_string())
}

/// `webrtc-rs`'s answer SDP uses bare `\n` and drops the terminator on the last
/// line; Chrome's parser requires RFC 4566's CRLF throughout, including after
/// the final line, and rejects the whole description otherwise.
fn normalize_sdp_line_endings(sdp: &str) -> String {
    let mut normalized = sdp.replace("\r\n", "\n").replace('\n', "\r\n");
    if !normalized.ends_with("\r\n") {
        normalized.push_str("\r\n");
    }
    normalized
}

impl ScreenShare {
    /// Whether a teacher currently has this device's screen open — the only
    /// state the on-screen "you're being watched" indicator needs.
    pub fn is_watching(&self) -> bool {
        self.watch.lock().unwrap().is_some()
    }

    /// Ends the watch, if any: stops capturing and closes the connection. Safe to
    /// call when nothing is being watched.
    async fn teardown(&self) {
        let watch = self.watch.lock().unwrap().take();
        if let Some(watch) = watch {
            if let Some(capture) = watch.capture {
                let _ = capture.stop();
            }
            let _ = watch.peer_connection.close().await;
        }
    }

    fn current_session_id(&self) -> Option<String> {
        self.watch.lock().unwrap().as_ref().map(|w| w.session_id.clone())
    }

    fn cursor_for(&self, session_id: &str) -> i64 {
        self.watch.lock().unwrap().as_ref().filter(|w| w.session_id == session_id).map_or(0, |w| w.last_candidate_id)
    }

    /// Builds a fresh connection for a session we have not seen locally yet
    /// (normally `pending`; also covers a device restart mid-watch, where we cannot
    /// recover the old connection and simply re-answer — a known v1 limitation).
    async fn begin(&self, base_url: &str, token: &str, session: &SessionDto) {
        let offer = match serde_json::from_value::<RTCSessionDescription>(session.offer.clone()) {
            Ok(offer) => offer,
            Err(_) => return,
        };

        let Ok(peer_connection) = new_peer_connection().await else { return };
        let track = Arc::new(TrackLocalStaticSample::new(
            RTCRtpCodecCapability { mime_type: MIME_TYPE_H264.to_owned(), clock_rate: 90000, ..Default::default() },
            "video".to_owned(),
            format!("toh-klas-screen-{}", session.id),
        ));
        let Ok(rtp_sender) = peer_connection.add_track(track.clone()).await else { return };
        tokio::spawn(async move {
            let mut buf = vec![0u8; 1500];
            while rtp_sender.read(&mut buf).await.is_ok() {}
        });

        {
            let (base_url, token, session_id) = (base_url.to_string(), token.to_string(), session.id.clone());
            let http = reqwest::Client::new();
            peer_connection.on_ice_candidate(Box::new(move |candidate| {
                let (http, base_url, token, session_id) = (http.clone(), base_url.clone(), token.clone(), session_id.clone());
                Box::pin(async move {
                    let Some(candidate) = candidate else { return };
                    let Ok(init) = candidate.to_json() else { return };
                    let Ok(payload) = serde_json::to_value(init) else { return };
                    let _ = crate::api_client::post_screen_session_candidate(&http, &base_url, &token, &session_id, &payload).await;
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

        // Idempotent on the backend: if this session was already active (the restart
        // case above), the PATCH is accepted but ignored.
        let Ok(mut answer_json) = serde_json::to_value(&answer) else { return };
        if let Some(sdp) = answer_json.get("sdp").and_then(|v| v.as_str()) {
            answer_json["sdp"] = serde_json::Value::String(normalize_sdp_line_endings(sdp));
        }
        let _ = crate::api_client::answer_screen_session(&reqwest::Client::new(), base_url, token, &session.id, &answer_json).await;

        let capture = start_capture(track.clone(), target_for(&session.quality)).ok();
        *self.watch.lock().unwrap() = Some(Watch {
            session_id: session.id.clone(),
            peer_connection,
            track,
            capture,
            last_candidate_id: 0,
            quality: session.quality.clone(),
        });
    }

    /// Switches an already-open watch between thumbnail and full-view quality.
    /// Media Foundation doesn't support changing a live transform's output frame
    /// size, so this rebuilds the capture+encoder pair rather than reconfiguring
    /// it in place — but reuses the same track (and so the same peer connection),
    /// which the viewer already negotiated and never needs to touch again.
    fn apply_quality(&self, session: &SessionDto) {
        let mut guard = self.watch.lock().unwrap();
        let Some(watch) = guard.as_mut() else { return };
        if watch.session_id != session.id || watch.quality == session.quality {
            return;
        }
        if let Some(capture) = watch.capture.take() {
            let _ = capture.stop();
        }
        watch.capture = start_capture(watch.track.clone(), target_for(&session.quality)).ok();
        watch.quality = session.quality.clone();
    }

    /// Applies viewer candidates that arrived since our last poll of this same session.
    async fn apply_candidates(&self, session: &SessionDto) {
        let peer_connection = { self.watch.lock().unwrap().as_ref().map(|w| w.peer_connection.clone()) };
        let Some(peer_connection) = peer_connection else { return };

        let mut max_id = self.cursor_for(&session.id);
        for candidate in &session.candidates {
            max_id = max_id.max(candidate.id);
            if let Ok(init) = serde_json::from_value::<RTCIceCandidateInit>(candidate.payload.clone()) {
                let _ = peer_connection.add_ice_candidate(init).await;
            }
        }
        if let Some(watch) = self.watch.lock().unwrap().as_mut() {
            if watch.session_id == session.id {
                watch.last_candidate_id = max_id;
            }
        }
    }

    pub async fn tick(&self, state: &AppState) {
        let (base_url, token) = {
            let conn = state.db.lock().unwrap();
            (crate::db::config_repo::get_api_base_url(&conn).ok().flatten(), crate::credential_store::get_token())
        };
        let (Some(base_url), Some(token)) = (base_url, token) else { return };

        let after = self.current_session_id().map_or(0, |id| self.cursor_for(&id));
        let Ok(session) = crate::api_client::fetch_current_screen_session(&state.http, &base_url, &token, after).await else { return };

        let Some(session) = session.and_then(|value| serde_json::from_value::<SessionDto>(value).ok()) else {
            self.teardown().await;
            return;
        };

        if self.current_session_id().as_deref() == Some(session.id.as_str()) {
            self.apply_candidates(&session).await;
            self.apply_quality(&session);
        } else {
            self.teardown().await;
            self.begin(&base_url, &token, &session).await;
        }
    }
}

#[cfg(test)]
mod tests {
    use super::normalize_sdp_line_endings;

    #[test]
    fn a_missing_trailing_terminator_is_added() {
        let sdp = "v=0\r\ns=-\r\na=sendonly";
        assert_eq!(normalize_sdp_line_endings(sdp), "v=0\r\ns=-\r\na=sendonly\r\n");
    }

    #[test]
    fn bare_lf_is_upgraded_to_crlf_throughout() {
        let sdp = "v=0\ns=-\na=sendonly\n";
        assert_eq!(normalize_sdp_line_endings(sdp), "v=0\r\ns=-\r\na=sendonly\r\n");
    }

    #[test]
    fn already_correct_input_is_left_unchanged() {
        let sdp = "v=0\r\ns=-\r\n";
        assert_eq!(normalize_sdp_line_endings(sdp), sdp);
    }
}
