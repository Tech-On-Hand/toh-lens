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

## Identity on a shared computer

A student login session ends the moment the kiosk cannot be sure the person at the keyboard is still the one who signed in — not just when they click Log Out:

- **20 minutes idle** (no keyboard/mouse activity), with a 60-second on-screen warning first.
- **Any suspected sleep/hibernate**, however brief, logs out at once with no warning. Detected by comparing real elapsed time (`Date.now()`) between ticks of a 2-second timer against the interval itself: a gap over 10 seconds means the OS suspended the process (timers don't fire while suspended), not that the tab was merely slow.
- **Kiosk startup always reclaims a session left open** by a crash, forced power-off, restart, or an update, closing it before the app shows anything interactive. The kiosk never resumes straight into a session on start; it always lands on the keypad, with an on-screen notice when there was something to reclaim.

In every case, the next thing on screen is the keypad — access requires the admission number again, since the previous student's `logout_time` being unset must never be read as "still them."

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

Direction: each student device streams its own screen to at most one watching teacher at a time (not fan-out). One `webrtc-rs` connection per watched device, switchable between thumbnail and full-view quality on that same connection. Teacher-broadcast — one device's screen shown to the whole class — is a separate, independent mechanism; see its own section below.

The device has no live socket; it polls, same as commands and policy. The teacher already holds a Reverb socket for the classroom, so state changes the device makes are pushed to the teacher as a nudge (`device.screen.updated`, no payload beyond the ids) and the teacher re-fetches over REST. Nothing broadcasts to the device — it never listens.

## REST

| Method | Path | Principal | Purpose |
|---|---|---|---|
| POST | `/api/v1/teacher/classrooms/{c}/devices/{d}/screen-sessions` | Teacher (viewer+) | Start watching: body `{offer: {type:"offer", sdp}}`. `409 DEVICE_OFFLINE` / `409 SCREEN_ALREADY_WATCHED` |
| GET | `/api/v1/teacher/classrooms/{c}/devices/{d}/screen-sessions/{id}?after=` | Teacher (viewer+) | Poll for the answer and new device-side candidates |
| POST | `/api/v1/teacher/classrooms/{c}/devices/{d}/screen-sessions/{id}/candidates` | Teacher (viewer+) | Send the teacher's ICE candidates |
| POST | `/api/v1/teacher/classrooms/{c}/devices/{d}/screen-sessions/{id}/quality` | Viewer or controller | Switch quality: body `{quality: "thumb"\|"full"}` |
| POST | `/api/v1/teacher/classrooms/{c}/devices/{d}/screen-sessions/{id}/end` | Viewer or controller | Stop watching |
| GET | `/api/v1/device/screen-sessions/current?after=` | Active device | The device's own current session (`null` means stop capturing), plus new viewer-side candidates |
| PATCH | `/api/v1/device/screen-sessions/{id}` | Active device | Answer: body `{answer: {type:"answer", sdp}}`. Idempotent once active |
| POST | `/api/v1/device/screen-sessions/{id}/candidates` | Active device | Send the device's ICE candidates |

The teacher device-grid snapshot (`GET .../devices`) gains `watched_by`: `null` if nobody is watching or the requester is the one watching, otherwise the watching teacher's name.

## Lifecycle

`pending` (offer given, no answer yet) → `active` (answered) → `ended`. `end_reason`: `ended` (a person stopped it), `expired` (device never answered within 30s), `device_offline` (heartbeat went stale while watched), `device_revoked`, `viewer_lost` (the teacher's own side went quiet for 30s while active — their app closed, crashed, or lost its connection without ending the session; nothing else notices this case, since the device stays online and keeps capturing). The teacher's poll of an active session (`GET .../screen-sessions/{id}`) doubles as its liveness signal.

Only one `pending`/`active` session per device (`current` scope); starting a second is refused, not queued. ICE candidates are stored per-session, tagged by source (`device`/`viewer`), and fetched with a client-tracked `after` cursor — the same shape on both sides.

## Capture and encoding (device side)

Windows Graphics Capture of the primary monitor, downscaled with a box/area filter (not cropped — `windows-capture` always hands over the monitor's real resolution regardless of target size) and encoded with the same Media Foundation H.264 encoder proven in `spikes/native-capture`. Two quality presets, chosen by the session's `quality` column: `thumb` (`320x180`, 5fps, ~150 kbps, the default) and `full` (`1280x720`, 15fps, ~1.5 Mbps, requested on demand for a closer look at one device). Nothing captured or encoded while unwatched.

Switching quality mid-watch rebuilds the capture+encoder pair rather than reconfiguring it live — Media Foundation doesn't support changing a transform's output frame size once streaming has started — but reuses the same peer connection and track, so the viewer never re-negotiates. `webrtc-rs`, one public STUN server configured (`stun:stun.l.google.com:19302`) and nothing else — still fully direct P2P, not a relay like TURN (still absent — see below for why STUN alone was necessary, and why the empty-ice-servers design the spikes proved doesn't quite hold across two real machines).

**Chromium's mDNS-obfuscated local candidates, and why STUN was needed to work around it.** Any peer running in a Chromium-based webview (the Teacher app watching a device, or a receiving kiosk in a broadcast) offers its local ICE candidate as a random `xxxxxxxx-....local` hostname instead of a real IP — a privacy feature that requires the other side to resolve it via multicast DNS before a connection can complete. On one machine this never mattered (loopback works either way); across two real machines it silently breaks the connection the moment mDNS doesn't reach both sides — candidates still get exchanged over the REST signaling either way, so the session looks `active` with an answer and candidates on both sides, but no track ever arrives. The usual fix (`--force-webrtc-ip-handling-policy=default_public_and_private_interfaces`, set via `additionalBrowserArgs` in all three `tauri.conf.json`s) did not work: confirmed present in the running WebView2 process's own command line, yet the candidate stayed obfuscated — WebView2 does not honor this switch the way Electron/plain Chromium does. Adding the STUN server sidesteps the problem instead of fixing it: it gives the ICE agent a second, real-IP candidate that doesn't depend on mDNS at all, so the connection succeeds via that one even when the obfuscated candidate is unusable. A network with no outbound access to the STUN server is the resulting gap — same "surfaced as can't connect, not silently retried" behavior as the TURN gap.

## Teacher broadcast

One device's screen, shown live on every other online kiosk in the classroom — direct kiosk-to-kiosk connections (the "one-encode-N-sends" pattern proven in `spikes/native-capture`), not relayed through the teacher or backend, which only coordinate who is broadcasting to whom. Only available on a device already being watched by the teacher who starts it; runs as its own independent capture+encode pipeline, fixed at `1280x720`/15fps/~1.5 Mbps, separate from that regular watch session so the two never interfere with each other's quality.

Signaling direction is reversed from screen_sessions: each receiving kiosk is the offerer (the same role a teacher plays watching a device), and the source device is always the answerer for its own broadcast's targets (the same role a watched device plays) — structurally the same kind of connection, just device-to-device. The receiving side runs in the kiosk's own webview using its native WebRTC support, the same trick the Teacher app uses, rather than a Rust-side decoder.

### REST

| Method | Path | Principal | Purpose |
|---|---|---|---|
| POST | `/api/v1/teacher/classrooms/{c}/devices/{d}/broadcast` | Controller | Start broadcasting this device. `409 DEVICE_OFFLINE` / `409 CLASSROOM_ALREADY_BROADCASTING` |
| POST | `/api/v1/teacher/classrooms/{c}/devices/{d}/broadcast/{id}/end` | Controller | Stop broadcasting; ends every current target too |
| GET | `/api/v1/device/broadcasts/current?after=` | Any device | Is my classroom broadcasting, and (once joined) my own target's answer and new source-side candidates |
| POST | `/api/v1/device/broadcasts/{id}/join` | Any device | Join as a receiving kiosk: body `{offer}`. Idempotent — joining twice returns the same target |
| GET | `/api/v1/device/broadcasts/outgoing` | Source device | Every current target waiting for an answer or with new candidates, for the broadcast this device is the source of. No `after` cursor — resent in full each poll, since a classroom's kiosk count and per-connection candidate count are both small |
| PATCH | `/api/v1/device/broadcasts/targets/{uuid}` | Source device | Answer one target: body `{answer}`. Idempotent once active |
| POST | `/api/v1/device/broadcasts/targets/{uuid}/candidates` | Source or target device | Send ICE candidates; which side posted is inferred from which device is authenticated |

The teacher device-grid snapshot (`GET .../devices`) gains `broadcast_id`: the active broadcast's id if this device is currently the source, otherwise `null`.

### Lifecycle

A broadcast is `active` → `ended`. Only one active broadcast per classroom at a time (not per device), enforced the same way as one-viewer-per-device. Each target is `pending` (offer given) → `active` (answered) → `ended`, mirroring `screen_sessions.status` and its `end_reason`s exactly: `ended`, `expired` (source never answered within 30s), `target_lost` (the receiving kiosk's own poll — its liveness signal, same as a teacher's — went quiet for 30s while active), `source_offline`, `source_revoked` (both also end the broadcast itself and every current target, not just the one row).


# Milestone 5 contracts — communication (announcements, help requests, chat)

Teacher-to-class **announcements** and student **help requests** ("raise hand"). Collaborative-teaching permissions are not part of the MVP. As elsewhere, a device holds no socket: it polls, and the teacher's already-open socket gets a nudge.

## REST

| Method | Path | Principal | Purpose |
|---|---|---|---|
| POST | `/api/v1/teacher/classrooms/{c}/announcements` | Primary/assistant/admin | Send to every device in the classroom: `message` (1-500 chars), `duration_minutes?` (1-120, default 10) |
| GET | `/api/v1/teacher/classrooms/{c}/announcements` | Teacher (viewer+) | The 10 most recent, each with `total_devices`, `delivered`, `read` |
| GET | `/api/v1/teacher/classrooms/{c}/help-requests` | Teacher (viewer+) | Open requests, oldest first, with device and student name |
| POST | `/api/v1/teacher/classrooms/{c}/help-requests/{id}/resolve` | Primary/assistant/admin | Mark it handled. Idempotent |
| GET | `/api/v1/device/announcements` | Active device | This device's unread, unexpired announcements (oldest first, at most 5). Fetching marks each **delivered** |
| POST | `/api/v1/device/announcements/{id}/read` | Active device | The student dismissed it: marks it **read** |
| GET | `/api/v1/device/help-requests/current` | Active device | `{help_request}`: this device's open request, or `null` once it is resolved or cancelled |
| POST | `/api/v1/device/help-requests` | Active device | Raise a hand: body `{uuid, message?, session_uuid?}` (message up to 200 chars) |
| POST | `/api/v1/device/help-requests/{id}/cancel` | Active device | The student withdrew it |

The teacher device-grid snapshot (`GET .../devices`) gains `help_request`: `{id, status, message, requested_at}` for the device's open request, otherwise `null`.

## Behaviour

- **Announcements** last until `expires_at`, so a device that was offline or logged out when one was sent still receives it on its next poll. Delivery/read state is per device (`announcement_receipts`): `delivered` when first fetched, `read` when dismissed. The teacher sees counts, not who.
- **Help requests** are idempotent on the device-chosen `uuid`: re-sending the same one returns it (`200`) instead of raising a second, and a device with a request already open gets that one back rather than a new one. A `uuid` already used by another device is `409 REQUEST_ID_TAKEN`. The student is taken from the device's active login session, so a request raised on the keypad (nobody logged in) has no student.
- **Audit actions:** `announcement.sent`, `help.requested`, `help.resolved`.

## Realtime events

`private-classroom.{id}`: `classroom.communication.changed` with `kind` = `help` (raised, cancelled or resolved) or `announcement` (read). A nudge only; the Teacher app re-fetches over REST.

## On the kiosk

- **Announcements** show full-screen over everything (`AnnouncementOverlay`) until the student taps OK. While a student is logged in the kiosk window is hidden behind the desktop (see `shell_handoff.rs`); it is brought forward for the announcement and put away again once the last unread one is dismissed. With nobody logged in the window is already the screen.
- **Help.** The keypad screen has an "Ask for help" button. While a student is logged in the kiosk window is hidden, so a small always-on-top window (`help_widget.rs`, running the same frontend, chosen by window label) floats at the bottom-right of the primary monitor with the same button. It shows "Teacher notified" until the request is resolved or cancelled.

## Chat

A conversation is **one student's login session on one computer**: a teacher talks to whoever is signed in on that device right now, and the next student to sit down starts with an empty thread rather than reading the last one's. Messages are up to 500 characters.

| Method | Path | Principal | Purpose |
|---|---|---|---|
| GET | `/api/v1/teacher/classrooms/{c}/devices/{d}/messages` | Teacher (viewer+) | The current conversation (`{session, messages}`, newest 200). `session` is `null` when nobody is signed in. **Opening it marks the student's messages read** |
| POST | `/api/v1/teacher/classrooms/{c}/devices/{d}/messages` | Primary/assistant/admin | Send: body `{uuid, body}`. `409 NO_ACTIVE_STUDENT` if nobody is signed in |
| GET | `/api/v1/device/messages?after=` | Active device | `{session_uuid, unread, messages}` for the signed-in student, from `after` on. Fetching what a teacher sent marks it **delivered**. `unread` counts the teacher's messages not yet opened |
| POST | `/api/v1/device/messages` | Active device | The student writes to the teacher: body `{uuid, body, session_uuid?}`. Without `session_uuid` it goes to whoever is signed in (`409 NO_ACTIVE_STUDENT` if nobody is); with it, to that login session (`409 SESSION_UNKNOWN` if the server has not received that session yet) |
| POST | `/api/v1/device/messages/read` | Active device | The student opened the chat: everything the teacher sent counts as read |

Sending is idempotent on the sender-chosen `uuid` (a retry returns the same message; a `uuid` already used by someone else is `409 MESSAGE_ID_TAKEN`). Each message carries `delivered_at` and `read_at`, so the teacher's thread shows sent, delivered, or read. The teacher device-grid snapshot gains `unread_messages`, the number of the student's messages no teacher has opened. A student's message dispatches `classroom.communication.changed` with `kind` = `chat`.

On the kiosk the floating help bar has a "Chat" button (red with a count while there are unread messages); opening it grows the bar into a chat panel. Anything the student types while the connection is down is queued on disk and delivered later under the same `uuid` (see Offline queue).

## Offline queue (kiosk)

Everything a student does in this milestone is written to a durable on-disk queue first (`outbox` table in the kiosk's SQLite database, `outbox.rs`) and delivered in order, so a network outage or a kiosk restart loses nothing. The student's tap returns at once; delivery is attempted immediately in the background and then on every 3-second tick.

| Queued action | Delivered as |
|---|---|
| Chat message | `POST /device/messages` with `{uuid, body, session_uuid}` |
| Raised hand | `POST /device/help-requests` with `{uuid, message, session_uuid}` |
| Withdrawn hand | `POST /device/help-requests/{id}/cancel` (if the hand was never sent, it is simply removed and nothing is sent) |
| Dismissed announcement | `POST /device/announcements/{id}/read` |
| Opened the chat | `POST /device/messages/read` (at most one queued) |

**Attribution.** Chat messages and raised hands carry the `session_uuid` of the login session they were written in, so one delivered after that student has left is still credited to them and never to whoever is signed in by then. The server refuses a hand from a session that has ended (`409 SESSION_ENDED`) and asks the kiosk to wait for a session it has not received yet (`409 SESSION_UNKNOWN`, since sessions are synced separately from the outbox).

**Delivery rules.** Order is kept: delivery stops at the first entry that cannot be sent yet. Network failure, `5xx`, `408`, `429` and `SESSION_UNKNOWN` are retried. Any other refusal (`4xx`) means the server will never accept it, so the entry is dropped and a warning logged. A raised hand still unsent after 10 minutes is dropped (it is no longer a live request for help); chat messages never expire.

**What the student sees.** Messages not yet delivered show as "Not sent yet, retrying…" in the chat; a hand not yet delivered shows "Will send when you're back online"; an announcement the student dismissed offline stays dismissed and does not reappear when the connection returns. Announcements themselves, and anything from the teacher, still need a connection to arrive.


# Milestone 6 contracts — activity and reporting

## Desktop application activity

While a student is signed in, the Student Agent samples the **process name** of the foreground window (for example `WINWORD.EXE`) once per 3-second tick. **Window titles are never read or stored**: they carry document names and other personal content. Time with no keyboard or mouse input for 60 seconds is recorded as **idle**, kept separate from the application that happened to be in front. The kiosk's own windows are not recorded, and nothing is sampled while nobody is signed in.

Consecutive samples of the same application in the same state are merged into one interval. Intervals wait in the kiosk's SQLite database (`app_usage`) and are uploaded about every 30 seconds; one still open when it was uploaded is sent again once it has grown (the server keeps the later end, never an earlier one). Delivered intervals are pruned locally after 10 minutes and anything undelivered after 2 days.

| Method | Path | Principal | Purpose |
|---|---|---|---|
| POST | `/api/v1/device/app-activity` | Active device | Upload intervals: `{intervals: [{uuid, session_uuid, process, is_idle, started_at, ended_at}]}` (up to 200). Replies `{accepted: [uuid]}`; an interval whose login session has not reached the server yet is left out so the agent retries it after the session syncs |
| GET | `/api/v1/teacher/classrooms/{c}/reports/activity?from=&to=` | Teacher (viewer+) | The activity report for `from`..`to` (`Y-m-d`, server timezone, default today, at most 31 days: `422 RANGE_TOO_LONG`) |

## Activity report

Per student login session that began in the range: `student`, `device`, `login_time`, `logout_time`, `signed_in_minutes`, `active_minutes`, `idle_minutes`, `apps` (top 5 by active minutes, each `{process, name, minutes}`; `name` is a friendly name such as Word or Chrome when known, otherwise the process name), `sites` (top 5 domains by number of navigations) and `blocked_attempts`. The reply also carries `classroom_apps`, the classroom's ten most used applications across all sessions in the range. Applications under 30 seconds are left out, and idle time is never credited to an application. The Teacher app shows it in a "Reports" panel with Today / Yesterday / Last 7 days presets and custom dates.

## Retention

Browsing history and application activity older than `TOH_ACTIVITY_RETENTION_DAYS` (default 90, `config/toh.php`) are deleted by `php artisan activity:prune`, scheduled daily at 02:30 (`--days=` overrides it once). Schools and parents should be told what is collected and for how long, as with browser URLs.


## Audit history and fleet health (school administrators)

| Method | Path | Principal | Purpose |
|---|---|---|---|
| GET | `/api/v1/admin/audit?school_id=&classroom_id=&action=&before_id=&limit=` | School administrator | Newest first, at most 100. Each entry now also carries `classroom_name` and `device_name` |
| GET | `/api/v1/admin/fleet?school_id=` | School administrator | Every non-revoked device in the school with its `issues`, devices needing attention first, plus a `summary` (`devices`, `online`, `needing_attention`, `newest_agent_version`) |

The Teacher app shows both to administrators as "Audit" and "Fleet" buttons. The audit panel filters by whole school or one classroom and by action, labels every action in plain words and pages back with "Load older"; entries are read-only.

**Health on the heartbeat.** `POST /device/heartbeat` accepts an optional `health` object: `unsynced_sessions` (login sessions the agent has recorded but the server has not confirmed) and `screen_capture_supported` (whether this Windows build can capture the screen at all). It replaces the last one on every heartbeat; an older agent that sends none leaves the last known state alone.

**Issues** are only raised for something the device reported or the server can see, so they can be trusted: `never_seen` (enrolled, never connected), `offline` (not seen for 24 hours; a PC switched off overnight or at the weekend is not flagged), `outdated_agent` (older than the newest agent in that school), and, only while the device is online, `screen_capture` (Windows cannot capture the screen, so screen watch and broadcast will not work) and `sync_backlog` (5 or more sessions waiting to sync). Whether the browser extension is connected is deliberately not an issue: an idle PC with no browser open looks identical to a broken one.


# Pilot rollout tooling

The step-by-step is `provisioning/pilot-rollout.md`. What the tooling is:

**Server (artisan).**
- `php artisan klas:bootstrap "<organization>" "<school>" <admin_email> [--admin-name=] [--classroom=...]` creates the organization, school, classrooms and a school administrator (a new account gets a random password, printed once). Idempotent.
- `php artisan klas:enrollment-codes <classroom_id> [count] --as=<admin_email> [--minutes=1440] [--json]` issues up to 200 one-time codes at once, valid up to 7 days (the web admin's are 30 minutes). `--as` must be an administrator of that classroom's school; it is recorded as the issuer.
- `php artisan klas:check` reports PASS / WARN / FAIL for the environment (key, production mode, debug, https and a non-localhost `APP_URL`), database and pending migrations, Redis, queue, Reverb, mail, and whether the **scheduler** is really running (a heartbeat the scheduler stamps every minute), and exits non-zero on a FAIL.

**Unattended enrollment (kiosk).** If the agent starts with no configuration and finds `%ProgramData%\TOH Klas\provisioning.json`, it enrolls itself and deletes the file:

```json
{ "api_base_url": "https://klas.school.example", "enrollment_code": "AB12-CD34", "device_name": "Lab PC 01" }
```

`device_name` is optional (the computer's name is used). A file that cannot work, or an enrollment that fails, falls back to the normal setup screen with the reason shown.

**Windows scripts (`provisioning/`).** `provision-student-pc.ps1` installs the kiosk MSI silently, writes that file, installs the browser integration and optionally applies the hardening (`-DryRun` prints everything first). `verify-student-pc.ps1` is read-only and checks Windows screen-capture support, WebView2, the kiosk, the native host and browser policies, the kiosk log, the server, the clock and UDP reachability of Google's STUN server, exiting non-zero on a FAIL.


# Impact report (for donors and boards)

Answers "are the computers being used?" with aggregate numbers that can leave the school. It **measures use, not learning**: the school's own results and teacher feedback have to sit alongside it.

| Method | Path | Principal | Purpose |
|---|---|---|---|
| GET | `/api/v1/admin/impact?school_id=` or `?organization_id=&from=&to=` | School administrator (own school) or organization administrator | The report for one school, or every school in an organization. `from`/`to` are `Y-m-d` in the server's timezone, default the last 30 days, at most a year (`422 RANGE_TOO_LONG`) |

**Contents.** `computers` (enrolled, used at least once, never used after a week enrolled, not heard from for two weeks), `students` (reached, on the roster, percent), `usage` (sessions, hours signed in, active and idle hours from application tracking, average session, days with use), `availability`, `weekly` (every week in the period, including quiet ones), `tools` (programs and websites), `schools` (a breakdown, one row per school), and `notes` (what to know before trusting the numbers).

**Availability** is the share of school days (Monday to Friday) on which a computer was switched on that a student used it. "Switched on" comes from `device_activity_days`, one row per computer per day it connected, written by the heartbeat; history starts the day this shipped, and only days with a record are compared, so older sessions cannot skew it. It is `null` until there is any history.

**Privacy.** The report contains no student name, admission number or id, and nothing about an individual: only counts, hours and percentages. A program or website is listed only when at least **5 different students** used it (`tools.min_students`), so one student's habits cannot be picked out. The desktop itself (`explorer.exe`) is not counted as a tool.

**Approximations, stated in `notes`.** A session counts for at most 8 hours, and one that was never closed and is more than 12 hours old is not counted. Active/idle hours only exist for computers running a version that tracks applications.

The Teacher app shows it to administrators as an **Impact** panel: headline tiles, a weekly chart, top programs and sites, a school breakdown for an organization, a plain-language summary, a box for the school's own results (kept on that computer and included in the copy and the printout) and buttons to copy the summary or print / save as PDF. The printout leaves out the controls and the internal "needs attention" line, and adds a title.
