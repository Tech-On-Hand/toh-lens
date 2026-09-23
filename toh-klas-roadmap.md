# TOH Klas Roadmap

## Milestone 1 — Foundation

- Multi-tenant organizations, schools, physical classrooms, and scoped staff roles.
- Invitation-only staff access and one-time device enrollment.
- Student Agent heartbeat, configuration refresh, offline identity/session behavior, and secure credential storage.
- Teacher desktop login, authorized classroom selection, live device grid/list, active-student context, and administrator revocation.
- Redis-backed Reverb events and compatibility with existing kiosk sync clients.

## Milestone 2 — Browser integration

Chrome/Edge managed extension, native messaging host, current-tab activity, open/close/navigate tab actions, and session-correct attribution.

**Status:** built and tested in software (backend APIs, agent bridge, native host, extension, installer, and a Teacher app Browser panel that shows the active tab and can open, redirect, or close pages). Verified in real headless Chrome 153 and Edge 153 (extension + native host + a stand-in agent); not yet run with the real agent on a school machine, and the Teacher UI is type-checked but has not been looked at in a running window. See `provisioning/browser-integration.md`.

## Milestone 3 — Classroom policies

Allow/block rules, focus sessions, offline policy cache, audit records, and reversible browser enforcement. Stronger Windows/network enforcement remains optional and separately reviewed.

**Status:** built and tested in software: backend policy and audit APIs, agent policy sync and offline cache, extension enforcement (verified in real Chrome 153 and Edge 153, including offline enforcement and automatic focus expiry), and a Teacher app panel. Not yet run with the real agent on a school machine, the Teacher UI has not been looked at in a running window, and there is no audit screen yet. See `provisioning/browser-integration.md`.

## Milestone 4 — Screen collaboration

Windows Graphics Capture, adaptive WebRTC thumbnails/full view, teacher broadcast, signaling, TURN deployment, and visible privacy indicators.

**Status:** thumbnail-quality one-viewer-per-device watching is built and type-checked end to end: backend signaling API, the kiosk agent's real capture→encode→WebRTC pipeline (Windows Graphics Capture of the primary monitor, Media Foundation H.264, `webrtc-rs`), and a Teacher app "Watch" button per device rendering the live thumbnail (`RTCPeerConnection` runs directly in the Teacher app's webview, signaling through the same REST endpoints the device polls). Not yet run against a real school machine. Deliberately out of scope for this slice: full-view escalation, teacher-broadcast, TURN (direct connections only — a network that needs a relay surfaces as "can't connect," not a silent retry), and an on-device "you're being watched" indicator for the student.

## Milestone 5 — Communication

Teacher/student chat, announcements, help requests, collaborative teaching permissions, delivery/read state, and offline-safe queues.

## Milestone 6 — Operations

Scheduling, activity/reporting, audit history, silent installers, managed extension deployment, signed updates, fleet health, retention controls, and pilot-to-school rollout tooling.
