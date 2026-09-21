# TOH Klas Roadmap

## Milestone 1 — Foundation

- Multi-tenant organizations, schools, physical classrooms, and scoped staff roles.
- Invitation-only staff access and one-time device enrollment.
- Student Agent heartbeat, configuration refresh, offline identity/session behavior, and secure credential storage.
- Teacher desktop login, authorized classroom selection, live device grid/list, active-student context, and administrator revocation.
- Redis-backed Reverb events and compatibility with existing kiosk sync clients.

## Milestone 2 — Browser integration

Chrome/Edge managed extension, native messaging host, current-tab activity, open/close/navigate tab actions, and session-correct attribution.

**Status:** built and tested in software (backend APIs, agent bridge, native host, extension, installer). Not yet run in a real browser on a real school machine, and the Teacher app does not show tabs or send commands yet. See `provisioning/browser-integration.md`.

## Milestone 3 — Classroom policies

Allow/block rules, focus sessions, offline policy cache, audit records, and reversible browser enforcement. Stronger Windows/network enforcement remains optional and separately reviewed.

## Milestone 4 — Screen collaboration

Windows Graphics Capture, adaptive WebRTC thumbnails/full view, teacher broadcast, signaling, TURN deployment, and visible privacy indicators.

## Milestone 5 — Communication

Teacher/student chat, announcements, help requests, collaborative teaching permissions, delivery/read state, and offline-safe queues.

## Milestone 6 — Operations

Scheduling, activity/reporting, audit history, silent installers, managed extension deployment, signed updates, fleet health, retention controls, and pilot-to-school rollout tooling.
