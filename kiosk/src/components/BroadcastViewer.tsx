import { invoke } from "@tauri-apps/api/core";
import { useEffect, useRef, useState } from "react";

// Matches the sync cadence style used elsewhere on the kiosk; the broadcast
// itself only needs to be noticed within a second or two of starting.
const POLL_MS = 1000;

// A STUN server only ever answers "what's your real address" once, during
// setup — it never carries media, so this is still a direct P2P connection,
// not a relay like TURN. Needed because this webview's local ICE candidate is
// an mDNS-obfuscated hostname (Chromium's privacy feature) that WebView2 won't
// disable via the usual --force-webrtc-ip-handling-policy flag; STUN gives the
// source kiosk a second, real-IP candidate to fall back to when mDNS
// resolution doesn't reach across two separate machines.
const ICE_SERVERS: RTCIceServer[] = [{ urls: "stun:stun.l.google.com:19302" }];

interface BroadcastStatus {
  broadcast: { id: string; source_device_name: string } | null;
  target: {
    id: string;
    status: "pending" | "active" | "ended";
    answer: RTCSessionDescriptionInit | null;
    candidates: { id: number; payload: RTCIceCandidateInit }[];
  } | null;
}

/**
 * Shows the classroom's current teacher-broadcast, if any, full-screen over
 * whatever else is on this kiosk (keypad or logged-in). This device plays the
 * offerer role here — the same role the Teacher app plays watching a device —
 * against a source kiosk answering directly (see screen_broadcast.rs); there
 * is no live socket, so this poll doubles as both discovery and signaling.
 */
export function BroadcastViewer() {
  const [active, setActive] = useState(false);
  const [sourceName, setSourceName] = useState("");
  const videoRef = useRef<HTMLVideoElement>(null);

  useEffect(() => {
    let cancelled = false;
    const pcRef: { current: RTCPeerConnection | null } = { current: null };
    const targetIdRef: { current: string | null } = { current: null };
    const cursorRef: { current: number } = { current: 0 };
    const pendingCandidatesRef: { current: RTCIceCandidateInit[] } = { current: [] };

    const teardown = () => {
      pcRef.current?.close();
      pcRef.current = null;
      targetIdRef.current = null;
      cursorRef.current = 0;
      pendingCandidatesRef.current = [];
      if (videoRef.current) videoRef.current.srcObject = null;
      if (!cancelled) setActive(false);
    };

    const flushCandidates = () => {
      const targetId = targetIdRef.current;
      if (!targetId || pendingCandidatesRef.current.length === 0) return;
      const batch = pendingCandidatesRef.current;
      pendingCandidatesRef.current = [];
      for (const candidate of batch) {
        void invoke("post_broadcast_candidate", { targetId, candidate }).catch(() => {});
      }
    };

    const join = async (broadcastId: string) => {
      const pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
      pcRef.current = pc;
      pc.addTransceiver("video", { direction: "recvonly" });
      pc.ontrack = (event) => {
        if (videoRef.current) videoRef.current.srcObject = event.streams[0];
      };
      pc.onicecandidate = (event) => {
        if (!event.candidate) return;
        pendingCandidatesRef.current.push(event.candidate.toJSON());
        flushCandidates();
      };

      const offer = await pc.createOffer();
      await pc.setLocalDescription(offer);

      const target = await invoke<{ id: string }>("join_broadcast", {
        broadcastId,
        offer: { type: offer.type, sdp: offer.sdp ?? "" },
      });
      targetIdRef.current = target.id;
      flushCandidates();
      if (!cancelled) setActive(true);
    };

    const poll = async () => {
      try {
        const status = await invoke<BroadcastStatus>("get_broadcast_status", { after: cursorRef.current });
        if (cancelled) return;

        if (!status.broadcast) {
          if (pcRef.current) teardown();
          return;
        }
        setSourceName(status.broadcast.source_device_name);

        if (!status.target) {
          if (!pcRef.current) await join(status.broadcast.id);
          return;
        }

        const pc = pcRef.current;
        if (!pc) return;
        if (status.target.answer && pc.remoteDescription === null) {
          await pc.setRemoteDescription(status.target.answer);
        }
        for (const candidate of status.target.candidates) {
          cursorRef.current = Math.max(cursorRef.current, candidate.id);
          try {
            await pc.addIceCandidate(candidate.payload);
          } catch {
            // A candidate that no longer applies (late/duplicate) isn't fatal.
          }
        }
      } catch {
        // Best-effort only — a failed poll just retries next tick.
      }
    };

    void poll();
    const interval = window.setInterval(() => void poll(), POLL_MS);
    return () => {
      cancelled = true;
      window.clearInterval(interval);
      teardown();
    };
  }, []);

  if (!active) return null;

  return (
    <div className="broadcast-overlay">
      <div className="broadcast-badge">Watching {sourceName}'s screen</div>
      <video ref={videoRef} autoPlay muted playsInline />
    </div>
  );
}
