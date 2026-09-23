import { useCallback, useEffect, useRef, useState } from "react";
import * as api from "./api";
import type { Device } from "./types";

// Matches the kiosk's poll cadence (browser_bridge.rs ticks every 3s); polling
// a bit faster here just keeps the teacher's view snappier, not more current.
const POLL_MS = 1000;

export default function ScreenThumbnail({ classroomId, device }: { classroomId: number; device: Device }) {
  const [watching, setWatching] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const videoRef = useRef<HTMLVideoElement>(null);
  const pcRef = useRef<RTCPeerConnection | null>(null);
  const sessionIdRef = useRef<string | null>(null);
  const cursorRef = useRef(0);
  const pollRef = useRef<number | null>(null);
  const pendingCandidatesRef = useRef<RTCIceCandidateInit[]>([]);

  const teardown = useCallback(
    (endRemote: boolean) => {
      if (pollRef.current !== null) {
        window.clearInterval(pollRef.current);
        pollRef.current = null;
      }
      pcRef.current?.close();
      pcRef.current = null;
      if (videoRef.current) videoRef.current.srcObject = null;
      if (endRemote && sessionIdRef.current) {
        void api.endScreenSession(classroomId, device.id, sessionIdRef.current).catch(() => {});
      }
      sessionIdRef.current = null;
      cursorRef.current = 0;
      pendingCandidatesRef.current = [];
      setWatching(false);
    },
    [classroomId, device.id],
  );

  // Stop watching if the card unmounts (classroom switch, device revoked, etc.)
  // rather than leaving an orphaned session tying up the device.
  useEffect(() => () => teardown(true), [teardown]);

  const flushCandidates = useCallback(() => {
    const sessionId = sessionIdRef.current;
    if (!sessionId || pendingCandidatesRef.current.length === 0) return;
    const batch = pendingCandidatesRef.current;
    pendingCandidatesRef.current = [];
    void api.sendScreenCandidates(classroomId, device.id, sessionId, batch).catch(() => {});
  }, [classroomId, device.id]);

  const poll = useCallback(async () => {
    const sessionId = sessionIdRef.current;
    const pc = pcRef.current;
    if (!sessionId || !pc) return;
    try {
      const session = await api.pollScreenSession(classroomId, device.id, sessionId, cursorRef.current);
      if (session.answer && pc.remoteDescription === null) await pc.setRemoteDescription(session.answer);
      for (const candidate of session.candidates) {
        cursorRef.current = Math.max(cursorRef.current, candidate.id);
        try {
          await pc.addIceCandidate(candidate.payload);
        } catch {
          // A candidate that no longer applies (late/duplicate) isn't fatal.
        }
      }
      if (session.status === "ended") {
        setError("The device stopped sharing its screen.");
        teardown(false);
      }
    } catch (reason) {
      // Unlike the "ended" branch above, the session is presumably still
      // active/pending server-side here (we just failed to use it locally) —
      // release it, or it keeps blocking anyone from watching this device.
      setError(String(reason));
      teardown(true);
    }
  }, [classroomId, device.id, teardown]);

  const startWatching = useCallback(async () => {
    setBusy(true);
    setError("");
    try {
      const pc = new RTCPeerConnection({ iceServers: [] });
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

      const session = await api.startScreenSession(classroomId, device.id, { type: offer.type, sdp: offer.sdp ?? "" });
      sessionIdRef.current = session.id;
      flushCandidates();
      setWatching(true);
      pollRef.current = window.setInterval(() => void poll(), POLL_MS);
      void poll();
    } catch (reason) {
      setError(String(reason));
      teardown(false);
    } finally {
      setBusy(false);
    }
  }, [classroomId, device.id, flushCandidates, poll, teardown]);

  const watchedByOther = device.watched_by !== null;

  return (
    <>
      <div className="screen-placeholder">
        {watching ? (
          <>
            <video ref={videoRef} autoPlay muted playsInline />
            <button className="thumb-stop" onClick={() => teardown(true)}>Stop</button>
          </>
        ) : (
          <>
            <span>{device.active_session?.student?.full_name?.slice(0, 1) ?? "—"}</span>
            {device.status === "online" && (
              <button
                className="thumb-watch"
                disabled={busy || watchedByOther}
                title={watchedByOther ? `Being watched by ${device.watched_by}` : undefined}
                onClick={() => void startWatching()}
              >
                {watchedByOther ? `Watching: ${device.watched_by}` : busy ? "Connecting…" : "Watch"}
              </button>
            )}
          </>
        )}
      </div>
      {error && <p className="thumb-error">{error}</p>}
    </>
  );
}
