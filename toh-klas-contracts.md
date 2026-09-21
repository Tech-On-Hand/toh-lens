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

Types: `browser.open_url {url}`, `browser.navigate {tab_id, url}`, `browser.close_tab {tab_id}`. URLs must be `http`/`https`. Issuing requires `student_session_id` to equal the device's active student session; otherwise the API returns `409 SESSION_MISMATCH`. An offline device returns `409 DEVICE_OFFLINE`. Commands default to a 60 s lifetime (10-300 s allowed).

Statuses: `pending` -> `delivered` -> `completed` | `failed`, or `expired`. A command is expired instead of delivered if the student session changed or the deadline passed. Delivery is at-most-once: a command handed to an agent is never re-sent, so a crash cannot open a tab twice; the teacher sees it expire and can re-issue.

Delivered commands use the reserved envelope (`version`, `id`, `type`, `organization_id`, `classroom_id`, `device_id`, `student_session_id`, `issued_by`, `issued_at`, `expires_at`, `payload`).

## Realtime events

- `private-classroom.{id}`: `device.browser.changed` (active tab or tab count changed), `device.command.updated`.
- `private-device.{uuid}`: `device.command.issued` (nudge only; the command itself is fetched over REST).
