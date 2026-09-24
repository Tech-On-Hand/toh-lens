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

**Status:** announcements (teacher to the whole classroom, shown full-screen on each kiosk until dismissed, with per-device delivered/read counts for the teacher) and help requests (a student raises a hand from the keypad screen or a floating button while logged in; the teacher sees it highlighted on the device grid and marks it done) are built and backend-tested (`CommunicationTest`). Teacher/student chat is built too: a conversation belongs to one student's login session on one computer, with sent/delivered/read state and an unread badge on the teacher's device grid; students chat from the floating help bar, which expands into a chat panel. Offline-safe queues are built: chat messages, raised hands, dismissed announcements and read receipts are written to an on-disk outbox on the kiosk first and delivered in order whenever the server can be reached, surviving an outage or a restart, and each is credited to the login session it was written in even if delivered after that student has left. Collaborative teaching permissions are out of scope for the MVP. The kiosk-side queue has unit tests for the storage and the delivery rules, but the delivery loop itself has not been exercised against a real server and a real outage. The kiosk window/widget behaviour has only been type-checked and built, not yet run on a hardened school machine. See `toh-klas-contracts.md`.

## Milestone 6 — Operations

Scheduling, activity/reporting, audit history, silent installers, managed extension deployment, signed updates, fleet health, retention controls, and pilot-to-school rollout tooling.

### Desktop application activity and reporting (built)

**Status:** built and tested at the API and storage level, not yet run end to end on a student PC. The Student Agent records which desktop application (by **process name only**, never window titles) the signed-in student has in front, separating idle time (no keyboard or mouse input for 60 s) from application time. It queues this on disk and uploads it, credited to the login session it happened in, and the Teacher app has a "Reports" panel showing, per student and date range, time signed in, active vs idle time, applications used, sites visited and blocked attempts. A daily job deletes browsing and application activity past a configurable retention period (default 90 days). The real foreground-window and idle-time calls were checked on a dev PC; the report UI and the upload against a live kiosk have not been. See `toh-klas-contracts.md`.

Not part of this item: **restricting** which apps can run. Tracking does not stop anyone from opening an app; blocking is Windows-level (AppLocker or similar, see `provisioning/windows-kiosk-hardening.md`) and would need its own review, as with the Milestone 3 note on stronger enforcement.

### Audit history and fleet health (built)

**Status:** built and backend-tested, not yet seen on screen. School administrators get an "Audit" panel in the Teacher app (the audit trail was API-only until now: filter by school or classroom and by action, plain-language labels, older entries on demand) and a "Fleet" panel listing every device in the school with what needs attention: never connected, missing for over a day, running an older agent than the rest of the school, unable to capture the screen, or with a sync backlog. The agent now reports its own health on each heartbeat. See `toh-klas-contracts.md`.

### Still to do in this milestone

Scheduling, silent installers, managed extension deployment, signed updates, and pilot-to-school rollout tooling. The last four need decisions first (a code-signing certificate, where updates are hosted, how devices are managed).
