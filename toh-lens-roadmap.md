# TOH Lens — Implementation Roadmap

**Purpose:** track what's built, what's verified, and what's left to take TOH Lens from the current working prototype to the full system described in `toh-lens-srs.md`, following the 8-phase rollout plan in that document.

---

## 1. Current status

### Built and verified
- **Kiosk app** (`kiosk/`, Tauri v2 + React): full-screen numeric keypad login (FR-1.2–FR-1.4), local SQLite session log with synced/unsynced flags (FR-3.1–FR-3.3), background sync to the backend every 30s plus an immediate push after logout (FR-4.1–FR-4.2), crash/power-loss session resume, app-level kiosk mode (fullscreen, shortcut blocking).
- **Backend** (`backend/`, Laravel 12 + Sanctum, MySQL): `schools` / `classes` / `students` / `computers` / `login_sessions` schema scoped by `school_id` (FR-3.3 constraint), per-computer device tokens, `GET /api/roster`, `POST /api/sessions/sync` with UUID-keyed idempotent upsert (FR-4.2), `computer:issue-token` artisan command, demo seeder.
- **Admin UI** (`backend/`, Inertia + React, behind Fortify auth): create/list/delete for schools and classes, full create/edit/delete for students (admission number unique per school, enforced both client- and server-side) and computers, including a token issue/reissue action that rotates the previous token and shows the plaintext value exactly once, plus a per-computer sync health view (token last-used / last session synced) for remote diagnosis.
- **Kiosk resilience**: a 20-minute inactivity auto-logout fallback (SRS Risk #2), automatic roster re-sync every 6h when online with a staleness note past 24h (SRS Risk #5), and a real (release-build-only) `explorer.exe` handoff on login/logout.
- **M&E reporting (partial, SRS FR-5.3)**: a per-class/per-period usage report (total/active students, average session duration, students with no recorded usage) computed entirely from `login_sessions` — no LanSchool dependency. "Most-used application" is an explicit placeholder pending LanSchool data.
- **Live monitoring**: a "who's logged in right now" view across all schools/computers, auto-refreshing every 20s — previously there was no way to see current lab usage without querying the database directly.
- **Bulk student import**: a per-school CSV upload (admission_number, full_name, optional class_name/is_active), upserting by admission number so re-uploads correct rather than duplicate — the one-at-a-time form doesn't scale past a pilot classroom.
- **Verified live:** offline login/logout, crash resume, real sync against the backend, cross-school data isolation, 72 automated backend tests passing (kiosk sync API + admin UI + reporting).
- **Documented, not applied:** real Windows kiosk hardening (Winlogon shell swap, autologon, Task Manager disable) — see `provisioning/windows-kiosk-hardening.md`.

### Explicitly out of scope so far
- Any LanSchool Air integration (SRS §3.2 / Phase 2) — including ingesting its activity export, which the M&E report's "most-used application" field needs.
- The join/ETL between `LoginSession` and LanSchool activity data (SRS FR-5.1).

---

## 2. Remaining work, by SRS rollout phase

### Phase 1 — Plan
Done (SRS + architecture diagram + this roadmap).

### Phase 2 — Set up LanSchool Air
Not started. All configuration/coordination work, no code:
- [ ] Verify TOH's LanSchool Air org account with LanSchool support (removes the "unverified organization" student permission popup — SRS Risk #4).
- [ ] Install LanSchool Air **Student** on lab computers, **Teacher** on the teacher computer (school-email SSO).
- [ ] Get LanSchool Air's offline/degraded behavior confirmed directly with LanSchool support (SRS Risk #1) — this blocks a confident Phase 5 pilot.
- [ ] Confirm the mechanism for exporting/pulling LanSchool's per-computer activity logs (needed for Phase 7).

### Phase 3 — Student Identity Gate
Mostly done. Remaining:
- [x] Real desktop handoff (SRS FR-1.5/FR-1.6): `record_login` spawns `explorer.exe` and hides the kiosk window, `record_logout` kills it and reclaims the window — gated to release builds only (`shell_handoff.rs`), a no-op in every dev build so it can't disrupt a developer's own desktop. **Not yet tested on a real hardened machine** (only compile-checked in both debug and release) — genuinely needs a real device with the shell actually replaced, which this dev machine deliberately is not. Known gap: a crash-then-relaunch after login doesn't re-detect an already-running handed-off `explorer.exe`.
- [ ] Apply the real OS hardening from `provisioning/windows-kiosk-hardening.md` to an actual machine and verify against its checklist.
- [x] Decide on **explicit vs. inferred logout** (SRS Risk #2) — implemented as explicit + a 20-minute inactivity fallback (60s warning, then auto-logout), reusing the same `record_logout` path as the manual button.

### Phase 4 — Monitoring & Data Collection
Mostly done. Remaining:
- [x] Roster staleness handling (SRS Risk #5): the roster now auto-refreshes every 6h whenever online (previously only fetched once, at Setup), plus a quiet keypad-screen note if it's gone 24h+ without updating.
- [x] Remote diagnosability (NFR 5.5): the Computers admin page now shows, per computer, when its token last authenticated and when a session last synced from it — diagnosable without touching the machine.

Phase 4 is now fully done.

### Phase 5 — Pilot One Classroom
Not started. Prerequisites before this can start for real:
- [ ] LanSchool offline-behavior verification (Phase 2 item) — SRS explicitly calls this out as needed before pilot.
- [ ] OS hardening applied to the pilot machine(s), not just documented.
- [ ] Explicit vs. inferred logout decision made and implemented.
- [x] A way to onboard the pilot classroom's real students/computers without hand-editing seed data — the admin UI now covers this.

### Phase 6 — Deploy to Full School
- [x] A bulk-import path for onboarding a full school's students at once — CSV upload, upserts by admission number, per-row issues reported without failing the batch. `StudentController@import`, `/admin/students` page.
- [ ] A repeatable install package/script for the kiosk app + OS hardening steps, so a technician can provision a new lab computer without manual file editing. **Needs real hardware to design against.**

### Phase 7 — TOH M&E
Partially done — the session-only half of the report exists. Remaining, all blocked on Phase 2 (LanSchool Air) actually existing first:
- [x] Per-class, per-period summary reports (SRS FR-5.3), except most-used application: total students, active students, average usage duration, students with no recorded usage — `backend/app/Http/Controllers/Admin/ReportController.php`, admin page at `/admin/reports`.
- [ ] Ingestion of LanSchool Air's exported activity logs into the backend (`LanSchool Activity` entity from SRS §6) — needs Phase 2 done first to know the actual export format/mechanism.
- [ ] The join/ETL process: match `LoginSession` to `LanSchool Activity` by `computer_id` + overlapping time window (SRS FR-5.1), producing `App Usage` records.
- [ ] Add "most-used application" to the existing report once the above exists.
- [ ] Decide how to model **attendance vs. usage** (SRS Risk #3) — a student with zero sessions currently looks identical to "not enrolled yet."

### Phase 8 — Expand to Other Schools
Not started, but should require no new code if Phase 6 is done right:
- [ ] Confirm the same kiosk build + install process + admin onboarding flow works unchanged for a second school, varying only configuration data.

---

## 3. Suggested order of operations

1. ~~Finish verifying what's already built~~ — kiosk build verified end-to-end live (offline, crash-resume, real sync). Kiosk hardening build (`npm run tauri:build:kiosk`) still untested.
2. ~~Minimal admin UI~~ — done: schools/classes/students/computers, including computer token issue/reissue.
3. ~~Decide + implement the logout strategy~~ — done: explicit + 20-minute inactivity fallback with a warning.
4. ~~Real `explorer.exe` handoff~~ — implemented, release-build-only, compile-verified but not yet run on real hardware.
5. ~~Build the session-only half of M&E reporting~~ — done: per-class/per-period report, no LanSchool dependency.
6. ~~Live "who's logged in now" monitoring view~~ — done.
7. ~~Bulk student CSV import~~ — done.
8. **LanSchool Air setup + org verification** (Phase 2) — coordination/config work with LanSchool itself, not something buildable in this repo. **Blocked on TOH staff, not code.** ← next up
9. **Apply OS hardening** to one real pilot machine, including the real desktop-handoff build, and run the verification checklist. **Needs real hardware.**
10. **Run the Phase 5 pilot** in one classroom.
11. **Finish Phase 7**: LanSchool activity ingestion + join/ETL, then add "most-used application" to the existing report.
12. **Scale out**: a kiosk install/provisioning package for Phase 6/8.

Everything code-actionable without LanSchool or real hardware is now done. What's left genuinely needs TOH staff action (Phase 2) or a physical machine (Phase 3/5/6 hardening) before more code makes sense.

---

## 4. Open risks carried over from the SRS (§7)

| # | Risk | Status |
|---|---|---|
| 1 | LanSchool Air's cloud dependency during outages | Unverified — needs direct confirmation from LanSchool support before pilot |
| 2 | Explicit vs. inferred logout | Resolved — explicit "Log Out" plus a 20-minute inactivity auto-logout fallback |
| 3 | Attendance vs. usage modeling | Unresolved — no attendance concept yet |
| 4 | LanSchool org verification | Not done |
| 5 | Roster sync freshness | Resolved — auto-refreshes every 6h when online, plus a staleness note past 24h |
