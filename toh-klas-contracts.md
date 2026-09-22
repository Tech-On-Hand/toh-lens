# TOH Klas Milestone 1 Contracts

All `/api/v1` responses use `{ "success": true, "data": ... }` or `{ "success": false, "error": { "code": "...", "message": "..." } }`.

## REST

| Method | Path | Principal | Purpose |
|---|---|---|---|
| POST | `/api/v1/auth/login` | Public, throttled | Exchange staff email/password for a scoped teacher token |
| POST | `/api/v1/auth/logout` | Teacher | Revoke current teacher token |
| POST | `/api/v1/invitations/accept` | Public, throttled | Accept an expiring staff invitation |
| POST | `/api/v1/devices/enroll` | Public, throttled | Exchange a one-time enrollment code for a device token |
| GET | `/api/v1/device/configuration` | Active device | Read current device assignment/version |
| POST | `/api/v1/device/heartbeat` | Active device | Update presence and retrieve configuration |
| GET | `/api/v1/device/roster` | Active device | Fetch the school-scoped active roster |
| POST | `/api/v1/device/student-sessions/sync` | Active device | Idempotently sync UUID-keyed student sessions |
| GET | `/api/v1/teacher/classrooms` | Teacher | List authorized physical classrooms |
| GET | `/api/v1/teacher/classrooms/{id}/devices` | Teacher | Snapshot devices and active students |
| `/api/v1/admin/*` | Administrator | Manage classrooms, invitations, enrollment, assignments, and revocation |

The unversioned roster/session endpoints remain available to existing TOH Lens clients during migration.

## Realtime events

Private classroom channel: `private-classroom.{classroom_id}`. Private device channel: `private-device.{device_uuid}`.

- `device.presence.changed`
- `device.configuration.updated`
- `device.revoked`
- `student.session.started`
- `student.session.ended`

Domain events contain `event_id` and `occurred_at`. Clients deduplicate by `event_id`, reload a REST snapshot after reconnect, and apply exponential backoff capped at 30 seconds.

## Reserved future command envelope

```json
{
  "version": 1,
  "id": "uuid",
  "type": "browser.open_url",
  "organization_id": 1,
  "classroom_id": 12,
  "device_id": 41,
  "student_session_id": "uuid-or-null",
  "issued_by": 7,
  "issued_at": "ISO-8601",
  "expires_at": "ISO-8601",
  "payload": {}
}
```

The native browser bridge will use `{ version, id, type, occurred_at, payload }` with one response per request and explicit `completed`, `failed`, or `expired` status.

# Milestone 2 contracts — browser integration

## REST

| Method | Path | Principal | Purpose |
|---|---|---|---|
| POST | `/api/v1/device/browser/events` | Active device | Report a tab snapshot and/or navigation events |
| GET | `/api/v1/device/commands` | Active device | Fetch pending commands (at-most-once delivery) |
| POST | `/api/v1/device/commands/{id}/result` | Active device | Report `completed` or `failed` for a delivered command |
| GET | `/api/v1/teacher/classrooms/{c}/devices/{d}/browser-tabs` | Teacher (viewer+) | Full current tab list |
| POST | `/api/v1/teacher/classrooms/{c}/devices/{d}/commands` | Teacher (primary/assistant/admin) | Issue a browser command |
| GET | `/api/v1/teacher/classrooms/{c}/devices/{d}/commands/{id}` | Teacher (viewer+) | Read command status/result |

The teacher device snapshot gains `active_tab` (`tab_id`, `url`, `title`, `observed_at`, or `null`).

## Tab state vs. history

- **State** is snapshot-driven: `snapshot: { observed_at, session_uuid, tabs: [{ tab_id, window_id, url, title, active }] }` replaces every tab for that device and browser. Snapshots older than the stored state are ignored, so late offline uploads cannot resurrect closed tabs. `active` means "the tab the student is looking at" (active in the focused window).
- **History** is an append-only log of `{ uuid, type: navigated|activated, url, title, session_uuid, occurred_at }`, idempotent on `uuid`.
- **Attribution** uses the `session_uuid` captured on the device when the event happened, never the server's "now", so events queued offline stay with the right student. A session id that does not belong to the reporting device is discarded.

## Commands

Types: `browser.open_url {url, browser?}`, `browser.navigate {browser, tab_id, url}`, `browser.close_tab {browser, tab_id}`. `browser` is `chrome` or `edge`; tab ids are only unique within one browser, so tab-specific commands must name it. URLs must be `http`/`https`. Issuing requires `student_session_id` to equal the device's active student session; otherwise the API returns `409 SESSION_MISMATCH`. An offline device returns `409 DEVICE_OFFLINE`. Commands default to a 60 s lifetime (10-300 s allowed).

Statuses: `pending` -> `delivered` -> `completed` | `failed`, or `expired`. A command is expired instead of delivered if the student session changed or the deadline passed. Delivery is at-most-once: a command handed to an agent is never re-sent, so a crash cannot open a tab twice; the teacher sees it expire and can re-issue.

Delivered commands use the reserved envelope (`version`, `id`, `type`, `organization_id`, `classroom_id`, `device_id`, `student_session_id`, `issued_by`, `issued_at`, `expires_at`, `payload`).

## Realtime events

- `private-classroom.{id}`: `device.browser.changed` (active tab or tab count changed), `device.command.updated`.
- `private-device.{uuid}`: `device.command.issued` (nudge only; the command itself is fetched over REST).

# Milestone 3 contracts — classroom policies

Two kinds of rule. **Blocked sites** are a standing block list, scoped to a whole school or one classroom. A **focus session** is a time-boxed allow-list for one classroom. Blocked sites always win: a domain that is blocked stays blocked even if a focus session allows it. Only one focus session can be active per classroom.

A rule is a domain and matches that domain and every subdomain (`example.com` covers `a.example.com`, not `notexample.com`). Input is normalized to a bare hostname (`https://www.X.com/p?q` becomes `www.x.com`); IP addresses, single-label names, and non-domains are rejected.

## REST

| Method | Path | Principal | Purpose |
|---|---|---|---|
| GET | `/api/v1/device/policy?known=<version>` | Active device | The device's effective policy; `{unchanged:true}` if `known` is current |
| GET | `/api/v1/teacher/classrooms/{c}/policy` | Teacher (viewer+) | Block rules (with scope) and the active focus session |
| POST | `/api/v1/teacher/classrooms/{c}/focus-sessions` | Primary/assistant/admin | Start focus: `allowed_domains` (1-100), `duration_minutes` (1-240), `name?`. `409 FOCUS_ALREADY_ACTIVE` if one is running |
| POST | `/api/v1/teacher/classrooms/{c}/focus-sessions/{id}/end` | Primary/assistant/admin | End it early. `409 FOCUS_NOT_ACTIVE` if already over |
| POST / DELETE | `/api/v1/teacher/classrooms/{c}/block-rules[/{rule}]` | Primary/assistant/admin | Add or remove a classroom rule. School rules return `403 SCHOOL_RULE` |
| POST / DELETE | `/api/v1/admin/block-rules[/{rule}]` | School administrator | School-wide or classroom rules |
| GET | `/api/v1/admin/audit?school_id=&classroom_id=&action=&before_id=&limit=` | School administrator | Newest first, at most 100 |

## Device policy

```json
{ "version": "sha1", "server_time": "ISO-8601", "block": ["games.example"],
  "focus": { "id": "uuid", "name": "Fractions", "allowed_domains": ["khan.example"],
             "started_at": "ISO-8601", "expires_at": "ISO-8601" } }
```

`version` changes whenever the effective policy does. `server_time` lets the agent turn `expires_at` into a deadline on the local clock (`now + (expires_at - server_time)`), so a wrong device clock can neither extend nor shorten a session. The agent caches the policy and the browser keeps enforcing it offline. A focus session past its deadline is inactive everywhere even before the expiry job records it.

## Audit actions

`focus.started`, `focus.ended`, `focus.expired`, `block_rule.added`, `block_rule.removed`, `browser.command_issued` (with the command payload and the student session), `device.updated`, `device.revoked`. Rows are append-only. Blocked navigations reported by the browser are stored as `browser_activities` with `event_type = blocked`.

## Realtime events

- `private-device.{uuid}`: `device.policy.changed` (nudge only; the policy is fetched over REST).
- `private-classroom.{id}`: `classroom.focus.changed` with `state` = `started`, `ended`, or `expired`.

## Browser enforcement

Enforced by the extension with `declarativeNetRequest` dynamic rules on top-level navigations only (page resources and embedded frames load normally). Blocked navigations go to a page inside the extension that shows why and carries the original address. Enforcement is reversible: when a rule is lifted, tabs parked on that page return to the address they were sent from. It covers Chrome and Edge only; other browsers, and anything that bypasses the extension, are not covered (see the deployment guide).

# Milestone 4 contracts — screen sharing (thumbnails)

Direction: each student device streams its own screen to at most one watching teacher at a time (not fan-out). One `webrtc-rs` connection per watched device, thumbnail quality only in this pass (no full-view escalation, no teacher-broadcast — see `spikes/README.md` for what those would need).

The device has no live socket; it polls, same as commands and policy. The teacher already holds a Reverb socket for the classroom, so state changes the device makes are pushed to the teacher as a nudge (`device.screen.updated`, no payload beyond the ids) and the teacher re-fetches over REST. Nothing broadcasts to the device — it never listens.

## REST

| Method | Path | Principal | Purpose |
|---|---|---|---|
| POST | `/api/v1/teacher/classrooms/{c}/devices/{d}/screen-sessions` | Teacher (viewer+) | Start watching: body `{offer: {type:"offer", sdp}}`. `409 DEVICE_OFFLINE` / `409 SCREEN_ALREADY_WATCHED` |
| GET | `/api/v1/teacher/classrooms/{c}/devices/{d}/screen-sessions/{id}?after=` | Teacher (viewer+) | Poll for the answer and new device-side candidates |
| POST | `/api/v1/teacher/classrooms/{c}/devices/{d}/screen-sessions/{id}/candidates` | Teacher (viewer+) | Send the teacher's ICE candidates |
| POST | `/api/v1/teacher/classrooms/{c}/devices/{d}/screen-sessions/{id}/end` | Viewer or controller | Stop watching |
| GET | `/api/v1/device/screen-sessions/current?after=` | Active device | The device's own current session (`null` means stop capturing), plus new viewer-side candidates |
| PATCH | `/api/v1/device/screen-sessions/{id}` | Active device | Answer: body `{answer: {type:"answer", sdp}}`. Idempotent once active |
| POST | `/api/v1/device/screen-sessions/{id}/candidates` | Active device | Send the device's ICE candidates |

The teacher device-grid snapshot (`GET .../devices`) gains `watched_by`: `null` if nobody is watching or the requester is the one watching, otherwise the watching teacher's name.

## Lifecycle

`pending` (offer given, no answer yet) → `active` (answered) → `ended`. `end_reason`: `ended` (a person stopped it), `expired` (device never answered within 30s), `device_offline` (heartbeat went stale while watched), `device_revoked`.

Only one `pending`/`active` session per device (`current` scope); starting a second is refused, not queued. ICE candidates are stored per-session, tagged by source (`device`/`viewer`), and fetched with a client-tracked `after` cursor — the same shape on both sides.

## Capture and encoding (device side)

Windows Graphics Capture of the primary monitor, encoded with the same Media Foundation H.264 encoder proven in `spikes/native-capture`: thumbnail profile (`~320x180`, low fps, ~150 kbps) while watched, nothing captured or encoded otherwise. `webrtc-rs`, empty ICE server list (direct connections only — this is the case the spikes proved works on a shared classroom LAN; a network that needs TURN falls back to "can't connect," which is surfaced, not silently retried forever).
