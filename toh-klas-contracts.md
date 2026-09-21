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
