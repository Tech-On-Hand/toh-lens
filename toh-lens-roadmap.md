# TOH Lens — Implementation Roadmap

**Purpose:** track what's built, what's verified, and what's left to take TOH Lens from the current working prototype to the full system described in `toh-lens-srs.md`, following the 8-phase rollout plan in that document.

---

## 1. Current status

### Built and verified
- **Kiosk app** (`kiosk/`, Tauri v2 + React): full-screen numeric keypad login (FR-1.2–FR-1.4), local SQLite session log with synced/unsynced flags (FR-3.1–FR-3.3), background sync to the backend every 30s plus an immediate push after logout (FR-4.1–FR-4.2), crash/power-loss session resume, app-level kiosk mode (fullscreen, shortcut blocking).
- **Backend** (`backend/`, Laravel 12 + Sanctum, MySQL): `schools` / `classes` / `students` / `computers` / `login_sessions` schema scoped by `school_id` (FR-3.3 constraint), per-computer device tokens, `GET /api/roster`, `POST /api/sessions/sync` with UUID-keyed idempotent upsert (FR-4.2), `computer:issue-token` artisan command, demo seeder.
- **Verified live:** offline login/logout, crash resume, real sync against the backend, cross-school data isolation, 45 automated backend tests passing.
- **Documented, not applied:** real Windows kiosk hardening (Winlogon shell swap, autologon, Task Manager disable) — see `provisioning/windows-kiosk-hardening.md`.

### Explicitly out of scope so far
- Any LanSchool Air integration (SRS §3.2 / Phase 2).
- Any M&E reporting or join logic (SRS §3.5 / Phase 7).
- An admin UI — schools/classes/students/computers are currently managed only via `php artisan db:seed` / a raw artisan command, not a real interface.
- The real Windows shell handoff — `LoggedInScreen` is a placeholder standing in for actually launching `explorer.exe`.

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
- [ ] Replace the placeholder `LoggedInScreen` with the real behavior: launch `explorer.exe` as a child process on successful login, reclaim control on logout (SRS FR-1.5).
- [ ] Apply the real OS hardening from `provisioning/windows-kiosk-hardening.md` to an actual machine and verify against its checklist.
- [ ] Decide on **explicit vs. inferred logout** (SRS Risk #2) — currently explicit-only (a "Log Out" click). If students forget, sessions stay open until the next login auto-closes them, which is a coarse approximation. Consider an inactivity timeout as a fallback.

### Phase 4 — Monitoring & Data Collection
Mostly done. Remaining:
- [ ] Roster staleness handling (SRS Risk #5): surface a warning (on the keypad screen or sync badge) when the cached roster hasn't refreshed in N days, so staff know a newly enrolled student won't validate yet.
- [ ] Remote diagnosability (NFR 5.5): right now, diagnosing a sync failure means someone reading the local SQLite file or backend logs directly. Consider a lightweight status export or admin-visible per-computer sync health view.

### Phase 5 — Pilot One Classroom
Not started. Prerequisites before this can start for real:
- [ ] LanSchool offline-behavior verification (Phase 2 item) — SRS explicitly calls this out as needed before pilot.
- [ ] OS hardening applied to the pilot machine(s), not just documented.
- [ ] Explicit vs. inferred logout decision made and implemented.
- [ ] At least a minimal way to onboard the pilot classroom's real students/computers (see admin UI below) rather than hand-editing seed data.

### Phase 6 — Deploy to Full School
Not started:
- [ ] A repeatable install package/script for the kiosk app + OS hardening steps, so a technician can provision a new lab computer without manual file editing.
- [ ] An admin UI or bulk-import path for onboarding a full school's classes/students/computers (SQL seeding does not scale past a demo).

### Phase 7 — TOH M&E
Not started — this is the layer that makes the whole system valuable to M&E staff:
- [ ] Ingestion of LanSchool Air's exported activity logs into the backend (`LanSchool Activity` entity from SRS §6).
- [ ] The join/ETL process: match `LoginSession` to `LanSchool Activity` by `computer_id` + overlapping time window (SRS FR-5.1), producing `App Usage` records.
- [ ] Per-class, per-period summary reports (SRS FR-5.3): total students, active students, average usage duration, most-used application, students with no recorded usage.
- [ ] Some way for M&E staff to actually see these reports — a dashboard page, an export, or both.
- [ ] Decide how to model **attendance vs. usage** (SRS Risk #3) — a student with zero sessions currently looks identical to "not enrolled yet."

### Phase 8 — Expand to Other Schools
Not started, but should require no new code if Phase 6 is done right:
- [ ] Confirm the same kiosk build + install process + admin onboarding flow works unchanged for a second school, varying only configuration data.

---

## 3. Suggested order of operations

1. **Finish verifying what's already built** — crash-resume test, true-offline test, kiosk hardening build (`npm run tauri:build:kiosk`) — see prior conversation for the exact steps.
2. **Minimal admin UI** for schools/classes/students/computers in the backend (it already has Inertia + React scaffolded from the starter kit) — this unblocks everything past "one demo school seeded by hand."
3. **Decide + implement the logout strategy** (explicit + inactivity fallback) — small, self-contained, and affects session-quality data downstream.
4. **Real `explorer.exe` handoff** in the kiosk Rust code.
5. **LanSchool Air setup + org verification** (Phase 2) — mostly independent of the above, can run in parallel.
6. **Apply OS hardening** to one real pilot machine and run the verification checklist.
7. **Run the Phase 5 pilot** in one classroom.
8. **Build the LanSchool ingestion + join/ETL + M&E reporting** (Phase 7) — this is the biggest remaining chunk of new code.
9. **Scale out**: deployment/provisioning docs and bulk onboarding for Phase 6/8.

---

## 4. Open risks carried over from the SRS (§7)

| # | Risk | Status |
|---|---|---|
| 1 | LanSchool Air's cloud dependency during outages | Unverified — needs direct confirmation from LanSchool support before pilot |
| 2 | Explicit vs. inferred logout | Unresolved — currently explicit-only with auto-close-on-next-login as a safety net |
| 3 | Attendance vs. usage modeling | Unresolved — no attendance concept yet |
| 4 | LanSchool org verification | Not done |
| 5 | Roster sync freshness | Partially mitigated (roster refresh exists) but no staleness warning yet |
