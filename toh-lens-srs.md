# Software Requirements Specification: TOH Lens

**Product:** TOH Lens — Computer Lab Identity & Monitoring System
**Organization:** Tech On Hand (TOH)
**Document status:** Draft, v1.0

---

## 1. Introduction

### 1.1 Purpose
This document specifies the requirements for TOH Lens, the system that identifies which student is using a shared computer lab machine at any given time, and combines that identity with classroom monitoring data from LanSchool Air to produce usage and engagement reports for TOH's Monitoring & Evaluation (M&E) function.

### 1.2 Scope
TOH Lens covers:
- A custom student identity gate running on shared Windows lab computers.
- Integration with LanSchool Air for classroom monitoring, screen control, and per-computer activity logging.
- Local, offline-first data capture with sync to a cloud backend.
- A reporting layer that joins identity data with LanSchool activity data for M&E purposes.

Out of scope: content filtering/website blocking policy, curriculum delivery (handled by the separate TOH Kids literacy app), and LanSchool Air's own teacher-facing monitoring UI, which is used as-is.

### 1.3 Definitions & Acronyms
| Term | Meaning |
|---|---|
| Admission number | A student's school-issued ID number, unique within a school but not globally |
| Kiosk gate | The custom login screen that captures admission number before a student can use a lab computer |
| LanSchool Air | Third-party cloud-based classroom monitoring/control product used for the Teacher/Student layer |
| M&E | Monitoring & Evaluation — TOH's function for measuring program impact |
| Session | One continuous period a single student is logged in via the kiosk gate on one computer |

### 1.4 References
- TOH Lens 8-phase rollout plan (Plan → LanSchool → Identity → Monitoring → Pilot → Deploy → M&E → Expand)
- TOH Lens workflow diagram (kiosk gate + LanSchool flow)
- TOH Lens data schema (ER diagram)

---

## 2. Overall Description

### 2.1 Product Perspective
TOH Lens is not a replacement for LanSchool Air — it is a thin identity layer that sits alongside it. LanSchool Air handles classroom control (screen viewing, locking, messaging) and produces its own per-computer activity logs. TOH Lens's custom component solves the one thing LanSchool Air does not: knowing which specific student was on a given shared computer at a given time, without requiring an individual school email account per student.

### 2.2 Product Functions (summary)
- Identify a student on a shared computer via a typed admission number, without a per-student Windows or LanSchool account.
- Record login/logout time, computer, and student for every session, working fully offline.
- Sync session records to a central backend when connectivity is available.
- Combine session records with LanSchool Air's own activity export to produce student-level (not just computer-level) usage data.
- Generate M&E reports per class, per period, from the combined dataset.

### 2.3 User Classes
| User | Role |
|---|---|
| Student | Logs into the kiosk gate with their admission number; uses the desktop for lessons |
| Teacher | Uses LanSchool Air's Teacher Console to monitor/control the classroom; signs in via school email SSO |
| TOH technical support staff | Deploys and maintains kiosk gate installs and LanSchool Air configuration across schools |
| TOH M&E staff | Consumes the joined reports; does not interact with the kiosk gate or LanSchool directly |

### 2.4 Operating Environment
- Shared Windows lab computers, one generic auto-login local account per machine.
- Schools have intermittent, often low-bandwidth internet connectivity.
- LanSchool Air (cloud-based) requires an organization-verified account and internet access for teacher sign-in, class start/end, and remote control.

### 2.5 Constraints
- Admission numbers are unique per school only — all identity lookups must scope by `school_id` + `admission_number` together, never by admission number alone.
- LanSchool Air identifies people via school email address and SSO; it cannot natively accept a typed admission number, so it cannot be the sole identity mechanism for this deployment.
- LanSchool Air's class start/end and student sign-in are cloud operations; behavior during an internet outage must be verified directly with LanSchool support before relying on it in the field.
- The kiosk account is a shared, low-trust local account — it must be hardened against basic bypass (Task Manager, key-combo shortcuts) since many different children use it daily.

### 2.6 Assumptions and Dependencies
- TOH's LanSchool Air organization account will be verified with LanSchool to remove the default "unverified organization" student-permission popup.
- Each lab computer is pre-configured with the correct `school_id` and, where applicable, `class_id` before deployment.
- A backend (Supabase or Firebase, per existing TOH architecture) is available for session sync, consistent with the TOH Kids app's offline-first pattern.

---

## 3. System Features (Functional Requirements)

### 3.1 Student Identity Gate
**Description:** A full-screen kiosk application that replaces the default Windows shell on the shared student account, requiring a valid admission number before releasing control of the desktop.

| ID | Requirement |
|---|---|
| FR-1.1 | The system shall launch the kiosk application automatically at boot, in place of the standard Windows desktop shell. |
| FR-1.2 | The system shall present a full-screen numeric keypad for admission number entry. |
| FR-1.3 | The system shall validate the entered admission number against a locally cached roster scoped to the computer's configured `school_id`. |
| FR-1.4 | The system shall display a clear error and return to the keypad on an invalid entry, without exposing which part of the number was wrong. |
| FR-1.5 | On a valid entry, the system shall record `school_id`, `admission_number`, `computer_id`, and `login_time`, then hand off control to the normal Windows desktop (`explorer.exe`). |
| FR-1.6 | The system shall provide a visible "Log Out" action that records `logout_time`, terminates the desktop session, and returns to the keypad screen. |
| FR-1.7 | The system shall prevent common bypass methods (Task Manager, Windows-key shortcuts) on the shared kiosk account. |

### 3.2 LanSchool Air Integration
**Description:** LanSchool Air runs independently in the background on every lab computer, providing classroom control and its own activity logging, unaware of the kiosk gate's identity layer.

| ID | Requirement |
|---|---|
| FR-2.1 | LanSchool Air Student shall be installed and running continuously on every student computer, independent of the kiosk gate's login state. |
| FR-2.2 | LanSchool Air Teacher shall be configured on the teacher's computer, signed in via school email SSO. |
| FR-2.3 | The TOH LanSchool Air organization account shall be verified with LanSchool to remove the default student remote-control permission prompt. |
| FR-2.4 | The system shall support exporting LanSchool Air's per-computer session/application activity logs for use in reporting. |
| FR-2.5 | LanSchool Air's behavior under an internet outage shall be documented and verified with LanSchool support prior to full deployment. |

### 3.3 Data Collection & Local Logging
| ID | Requirement |
|---|---|
| FR-3.1 | The system shall write every login/logout event to local storage immediately, regardless of internet connectivity. |
| FR-3.2 | The system shall mark each locally stored record as unsynced until successfully transmitted to the backend. |
| FR-3.3 | The system shall not lose or overwrite unsynced records if the computer is powered off before syncing. |

### 3.4 Sync & Backend Storage
| ID | Requirement |
|---|---|
| FR-4.1 | The system shall sync unsynced local session records to the backend automatically whenever internet connectivity is available. |
| FR-4.2 | The system shall retry failed syncs without duplicating already-synced records. |
| FR-4.3 | The backend schema shall scope all student and session records by `school_id` to preserve per-school admission number uniqueness. |

### 3.5 M&E Reporting
| ID | Requirement |
|---|---|
| FR-5.1 | The system shall join kiosk-gate session records with LanSchool Air activity records by matching `computer_id` and overlapping time windows. |
| FR-5.2 | The system shall produce a combined record per session containing student, class, computer, login/logout time, and applications used. |
| FR-5.3 | The system shall generate per-class, per-period summary reports including: total students, active students, average usage duration, most-used application, and students with no recorded usage. |

---

## 4. External Interface Requirements

### 4.1 User Interfaces
- **Kiosk keypad screen:** large touch/click-friendly numeric buttons, no reading required beyond numerals, suitable for primary-school-age students.
- **Teacher console:** LanSchool Air's own web/desktop interface; not modified by TOH Lens.

### 4.2 Hardware Interfaces
- Shared Windows desktop/laptop computers in school computer labs.
- No additional hardware (no card readers, biometrics) in the initial version.

### 4.3 Software Interfaces
- Windows shell/registry configuration (`Winlogon\Shell`, autologon) to install the kiosk app as the active shell.
- LanSchool Air (cloud service, third-party) for classroom monitoring and control.
- Backend service (Supabase or Firebase) for session data storage and sync.

### 4.4 Communication Interfaces
- HTTPS calls to the backend for sync, performed opportunistically when connectivity is detected.
- No direct communication between the kiosk gate and LanSchool Air; they are joined only at the reporting layer.

---

## 5. Non-Functional Requirements

### 5.1 Performance
- The kiosk gate shall add negligible delay to a normal Windows boot and login sequence.
- Roster validation shall complete instantly from the local cache (no network round-trip required at login time).

### 5.2 Reliability & Availability
- The kiosk gate shall function fully with no internet connection; only sync is connectivity-dependent.
- LanSchool Air's offline/degraded behavior is a known open risk (see Section 9) and shall be validated before the pilot.

### 5.3 Security & Privacy
- The shared kiosk Windows account shall be hardened against unauthorized access to other applications or system settings.
- Only admission number, timestamps, computer ID, and application usage are collected — no additional personal data, browsing content, or media capture beyond what LanSchool Air itself provides.
- Locally cached rosters shall contain only the minimum fields needed for login validation.

### 5.4 Usability
- The identity gate shall be usable by young or non-reading learners, consistent with TOH's existing design principle of minimizing reliance on text for this age group.
- Error states shall be simple and non-punitive (e.g., a gentle retry prompt rather than a technical error message).

### 5.5 Maintainability & Supportability
- TOH technical staff shall be able to remotely diagnose sync failures and kiosk gate issues without a site visit, where connectivity allows.
- Configuration (`school_id`, `computer_id`, roster cache) shall be updatable without reinstalling the kiosk application.

### 5.6 Portability & Scalability
- The same kiosk application, install process, and configuration method shall be reused unchanged across all schools (Phase 8), varying only by configuration data (`school_id`, roster).

---

## 6. Data Requirements

Core entities (see accompanying ER diagram for full field-level detail):

- **School** — top-level scoping entity for all other records.
- **Teacher** — linked to a school; signs into LanSchool Air via school email.
- **Class** — belongs to a school and a teacher; groups students and computers.
- **Student** — belongs to a school and class; identified by `admission_number`, unique only within its `school_id`.
- **Computer** — belongs to a school and class; tagged as Teacher or Student role.
- **Login Session** — one kiosk-gate login/logout event; the core identity record.
- **LanSchool Activity** — imported per-computer activity log from LanSchool Air; not directly linked by foreign key to Login Session, only joined at query time by `computer_id` and overlapping time.
- **App Usage** — per-application time breakdown attributed to a session after the join.
- **M&E Report** — derived, materialized summary per class per reporting period.

---

## 7. Open Issues & Risks

| # | Issue | Notes |
|---|---|---|
| 1 | LanSchool Air's cloud dependency | Class start/end and sign-in require internet; behavior during outages is unverified and should be confirmed with LanSchool support before the pilot. |
| 2 | Explicit vs. inferred logout | Relying on students to click "Log Out" is simpler but risks inaccurate session boundaries if forgotten; an inactivity-based fallback may be needed. |
| 3 | Attendance vs. usage | The current schema captures usage (time on a computer) but has no explicit attendance/absence concept — a student with zero sessions is indistinguishable from "not enrolled yet" without additional modeling. |
| 4 | LanSchool Air org verification | Must be completed with LanSchool directly; until then, the student remote-control permission popup will continue to appear. |
| 5 | Roster sync freshness | If a school has no connectivity for an extended period, newly enrolled students won't validate at the kiosk gate until the cached roster next syncs. |

---

## 8. Appendix: Rollout Phase Mapping

| Phase | Primary SRS sections involved |
|---|---|
| 1 — Plan | §2 Overall Description |
| 2 — Set up LanSchool Air | §3.2 |
| 3 — Student Identity Gate | §3.1 |
| 4 — Monitoring & Data Collection | §3.3, §3.4 |
| 5 — Pilot One Classroom | §5 Non-Functional Requirements, §7 Risks |
| 6 — Deploy to Full School | §5.5, §5.6 |
| 7 — TOH M&E | §3.5 |
| 8 — Expand to Other Schools | §5.6 |
