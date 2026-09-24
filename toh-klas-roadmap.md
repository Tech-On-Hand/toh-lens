# TOH Klas Roadmap

## Milestone 1 — Foundation

- Multi-tenant organizations, schools, physical classrooms, and scoped staff roles.
- Invitation-only staff access and one-time device enrollment.
- Student Agent heartbeat, configuration refresh, offline identity/session behavior, and secure credential storage.
- Teacher desktop login, authorized classroom selection, live device grid/list, active-student context, and administrator revocation.
- Redis-backed Reverb events and compatibility with existing kiosk sync clients.

## Milestone 2 — Browser integration

Chrome/Edge managed extension, native messaging host, current-tab activity, open/close/navigate tab actions, and session-correct attribution.

**Status:** built and verified end to end against the real agent and a real browser (extension + native host + Student Agent + Teacher app, all live) — not just the earlier headless-Chrome/stand-in-agent software tests. Not yet run on a real, hardened school machine (this was a dev PC). See `provisioning/browser-integration.md`.

## Milestone 3 — Classroom policies

Allow/block rules, focus sessions, offline policy cache, audit records, and reversible browser enforcement. Stronger Windows/network enforcement remains optional and separately reviewed.

**Status:** built and verified end to end against the real agent and a real browser: a focus session started from the Teacher app was enforced live — allowed sites loaded, everything else redirected to the blocked page — and a classroom block rule was enforced the same way. Not yet run on a real, hardened school machine, and there is no audit screen yet (the audit trail is API-only). See `provisioning/browser-integration.md`.

## Milestone 4 — Screen collaboration

Windows Graphics Capture, adaptive WebRTC thumbnails/full view, teacher broadcast, signaling, TURN deployment, and visible privacy indicators.

**Status:** one-viewer-per-device watching (switchable between thumbnail and full-view quality) and teacher-broadcast (one device's screen shown live to the whole classroom) are both built, backend-tested, and verified end to end against real kiosks. Watching: backend signaling + quality-switch API, the kiosk's capture→encode→WebRTC pipeline (Windows Graphics Capture of the primary monitor, box-downscaled — not cropped — to the target size, Media Foundation H.264, `webrtc-rs`), a Teacher app "Watch" button per device with a "Full view" toggle that rebuilds the encoder at `1280x720`/~1.5 Mbps on the same connection, and an on-device indicator (a persistent red banner, on both the keypad and logged-in screens) so the student can always see when their screen is being watched. Broadcast: direct kiosk-to-kiosk connections (one-encode-N-sends) started from a "Broadcast to class" button on a device already being watched, receiving kiosks decoding and displaying it in their own webview rather than a new Rust decoder, its own independent capture pipeline so it never fights the regular watch session's quality, and the same liveness-via-poll pattern as watching to catch a receiving kiosk that silently disappears. Not yet run on a real, hardened school machine. Deliberately out of scope: TURN (direct connections only — a network that needs a relay surfaces as "can't connect," not a silent retry; revisit once there's a real network to test it against).

## Milestone 5 — Communication

Teacher/student chat, announcements, help requests, collaborative teaching permissions, delivery/read state, and offline-safe queues.

## Milestone 6 — Operations

Scheduling, activity/reporting, audit history, silent installers, managed extension deployment, signed updates, fleet health, retention controls, and pilot-to-school rollout tooling.

### Desktop application activity (planned)

Today only browser activity is tracked (Milestone 2, via the Chrome/Edge extension); Word, Excel, File Explorer, games and any other desktop app are invisible to reporting. Planned:

- The Student Agent samples the foreground window's **process name** (e.g. `WINWORD.EXE`) every few seconds while a student session is open, attributed to that login session the same way browser activity is, queued in SQLite while offline and synced with the rest.
- **Process name only by default.** Window titles can contain document names and other personal content, so they are not recorded unless the school opts in, and students and parents are told what is collected (same disclosure standard as browser URLs).
- Activity/reporting shows time per application alongside browsing history, per student session.
- Idle time is recorded as idle rather than credited to whichever app was last in front.
- Retention follows the same controls as browser history.

Not part of this item: **restricting** which apps can run. Tracking does not stop anyone from opening an app; blocking is Windows-level (AppLocker or similar, see `provisioning/windows-kiosk-hardening.md`) and would need its own review, as with the Milestone 3 note on stronger enforcement.
