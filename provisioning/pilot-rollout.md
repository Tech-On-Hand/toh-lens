# Pilot rollout runbook

How to take TOH Klas from a built repository to a working pilot in one school. Work through it in order and do not skip the "prove it on one computer" step: everything is cheaper to fix on one PC than on thirty.

**What is proven and what is not.** The features have been run end to end on a development PC. The rollout scripts in this folder were written and dry-run there; the kiosk hardening and the whole flow on a locked-down school machine have **not** been run on real school hardware. Treat the first pilot computer as the test of this document, and write down what you had to change.

## 0. Before you start

| You need | Notes |
|---|---|
| A server | Runs the backend (PHP 8.3+, MySQL/MariaDB, Redis), reachable from every classroom over **https**. One central server is assumed; if the school's internet drops, teachers lose live control (students keep working, see the offline notes in `toh-klas-contracts.md`). |
| A domain and TLS certificate | Device tokens and student data cross the network. Do not pilot over plain http. |
| An admin's email address | The first school administrator. |
| The school's roster | A CSV of students, see step 2. |
| A build machine | Windows with Rust, Node and the WebView2/WiX/NSIS tooling. Builds are in step 3. |
| One spare student computer | Windows 10 1903 or newer for screen watch. Older Windows builds cannot hide the yellow screen-capture border, so students see it while watched (cosmetic; `verify-student-pc.ps1` tells you which case a computer is in). |
| Outbound UDP 19302 from student PCs | Screen watch and broadcast use Google's public STUN server to find each computer's address. Without it they cannot connect; everything else still works. |

## 1. Server

1. Deploy `backend/`, copy `.env.example` to `.env` and set at least: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<your server>`, the database, Redis, `QUEUE_CONNECTION=redis`, `BROADCAST_CONNECTION=reverb` with **your own** `REVERB_APP_ID/KEY/SECRET`, and a real `MAIL_MAILER` (staff invitations are emails). Optionally `TOH_ACTIVITY_RETENTION_DAYS` (default 90).
2. `php artisan key:generate`, then `php artisan migrate --force`.
3. Keep three things running: a web server for `public/`, `php artisan queue:work`, and `php artisan reverb:start`. Add one cron entry that runs `php artisan schedule:run` every minute. Without the scheduler computers are never marked offline and screen sessions never expire.
4. Run the readiness check and fix every **FAIL**; read every **WARN**:

   ```
   php artisan klas:check
   ```

## 2. Create the school

```
php artisan klas:bootstrap "Tech On Hand" "Pilot Primary School" head@school.example \
    --admin-name="Head Teacher" --classroom="Computer Lab" --classroom="Room 2"
```

This creates the organization, school, classrooms and the first school administrator, and prints that administrator's password **once**. It is safe to run again. Sign in to the web admin at `APP_URL`, change the password, and then:

* **Students.** Admin > Students > import a CSV with the columns `admission_number`, `full_name` and optionally `class_name` and `is_active` (rows without an admission number or name are skipped and reported). Re-importing updates rather than duplicates.
* **Teachers.** Admin > Klas > invite staff, and assign each to their classroom as primary teacher, assistant or observer.

## 3. Build what gets installed

| Artifact | Build | Result |
|---|---|---|
| Student kiosk | `cd kiosk; npm run tauri:build:kiosk` | `src-tauri/target/release/bundle/msi/*.msi` (use the **MSI**: it installs per machine) |
| Native messaging host | `cd browser-host; cargo build --release` | `target/release/toh-klas-native-host.exe` |
| Browser extension | see `browser-integration.md` | An extension id. Standalone (not domain-joined) PCs can only force-install a store-published extension, so publish it (an unlisted item is fine) and use the id the store gives you, or load it unpacked while testing. |
| Teacher app | `cd teacher; npm run tauri build` | Installer for the teachers' own computers. |

## 4. Prove it on one computer

Copy these to the spare student PC: the MSI, `toh-klas-native-host.exe`, and the three scripts `provision-student-pc.ps1`, `install-browser-integration.ps1`, `setup-kiosk-hardening.ps1`, `verify-student-pc.ps1` (all in this folder).

1. On the server, issue a code for that PC's classroom (find the classroom id in the output of `klas:bootstrap`):

   ```
   php artisan klas:enrollment-codes 1 1 --as=head@school.example
   ```

2. On the PC, in an **elevated** PowerShell, dry-run first and read it:

   ```powershell
   .\provision-student-pc.ps1 -KioskMsi .\kiosk.msi -BackendUrl https://klas.school.example `
       -EnrollmentCode AB12-CD34 -DeviceName "Lab PC 01" `
       -ExtensionId <id> -HostExePath .\toh-klas-native-host.exe -DryRun
   ```

   Then run it without `-DryRun`. Add `-SkipForceInstall` if you are loading the extension unpacked. Leave out `-StudentPassword` for now: do the hardening only after the rest works.
3. Start the kiosk (or sign in as the student account). It enrolls itself from the file the script left. The computer should appear in the Teacher app.
4. Verify:

   ```powershell
   .\verify-student-pc.ps1 -BackendUrl https://klas.school.example -ExtensionId <id>
   ```

   Fix every FAIL. The warnings explain themselves (for example "private windows are still allowed" until the hardening step).
5. Walk the checklist in section 7 on this one computer, with a teacher, before doing any more.
6. When it all works, apply the hardening (`windows-kiosk-hardening.md`: read it, dry-run, keep an administrator login on the machine) and re-run the verify script and section 7.

## 5. Roll out the rest

1. Issue one code per remaining computer. Codes are one-time and, from this command, last 24 hours (the web admin's last 30 minutes):

   ```
   php artisan klas:enrollment-codes 1 28 --as=head@school.example --minutes=1440
   ```

2. Run `provision-student-pc.ps1` on each PC with its own code and name, then sign in as the student account once so it enrolls. Do a handful, run `verify-student-pc.ps1` on each, and only then continue.
3. The administrator's **Fleet** panel in the Teacher app lists every enrolled computer and what needs attention (never connected, offline for over a day, an older agent than the rest, unable to capture the screen, a sync backlog). Use it as the roll-out tracker.

## 6. Teachers and disclosure

* Give each teacher the Teacher app installer, their login, and 20 minutes: the device grid, Watch / Full view, Broadcast, Focus and blocked sites, Announce, chat and help requests, Reports.
* **Tell students, parents and staff what is collected, and for how long, before the pilot starts.** As built: which browser tabs are open and the full URLs visited (including query strings), which desktop application is in front (process name only, never window titles or content), idle time, login and logout times, and, only while a teacher is watching, the live screen (a red banner shows the student when this is happening). Chat messages and help requests are stored with the student's session. Browsing and application activity are deleted after `TOH_ACTIVITY_RETENTION_DAYS` (default 90). Decide with the school whether that is acceptable.
* Decide who may see the Reports and Audit panels: the Audit and Fleet panels are for school administrators; a teacher sees reports for their own classrooms.

## 7. Go / no-go checklist (run on real hardware)

Tick each on the first computer, then on a sample of the rest.

- [ ] Student logs in with an admission number, and logs out; the session shows in the Teacher app.
- [ ] Log in with the network unplugged, then reconnect: the session syncs and shows the right student.
- [ ] Teacher opens a page on the student's browser; the student's active tab shows on the grid.
- [ ] A blocked site is blocked; a focus session lets only the listed sites through and ends on time, including with the network unplugged.
- [ ] Watch a student's screen (thumbnail, then Full view); the student sees the red banner; stopping clears it.
- [ ] Broadcast one screen to the classroom.
- [ ] Announcement appears on every computer, with the student logged in and not; the read count goes up when they tap OK.
- [ ] Student raises a hand (keypad screen and while logged in); the teacher sees it and marks it done.
- [ ] Teacher and student chat both ways; the next student on the same computer does not see the previous chat.
- [ ] Unplug the network, send a chat message and raise a hand, plug it back in: both arrive.
- [ ] Use Word or another app for a few minutes; it appears in the Reports panel.
- [ ] Private/Incognito windows and other browsers are handled the way the school expects (see `browser-integration.md`, Limits).
- [ ] `verify-student-pc.ps1` shows no FAIL, and the Fleet panel shows nothing needing attention.

## 8. Rolling back

* **One computer, browser side:** `.\install-browser-integration.ps1 -ExtensionId <id> -Uninstall`.
* **Kiosk:** uninstall "TOH Klas Student" from Apps, or `msiexec /x <the .msi> /qn`. Revoke the computer in the Teacher app (administrators) so its token stops working.
* **Hardening** has no automatic undo: reverse the changes listed in `windows-kiosk-hardening.md` (autologon, shell replacement, policies), or reimage.
* **The whole pilot:** stop the kiosks, then remove the school in the web admin. Delete `%ProgramData%\TOH Klas\` on each PC.

## 9. Known gaps for the pilot

* No signed installers or automatic updates: every update is a reinstall (`provision-student-pc.ps1` again). Windows SmartScreen will warn about unsigned installers.
* No relay (TURN): screen watch and broadcast need a direct path between computers plus outbound UDP to the STUN server. A network that blocks that will show "can't connect".
* Timetable scheduling of focus sessions and rules is not built (they start when a teacher starts them).
* The hardening script and the whole flow have not been proven on a locked-down school machine.
* A message or hand queued while a kiosk is offline is kept on disk, but a raised hand older than 10 minutes is dropped.
