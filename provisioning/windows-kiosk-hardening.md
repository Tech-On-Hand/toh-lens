# Windows kiosk hardening (deployment-time only — not applied in dev)

This document describes the real OS-level lockdown for a deployed lab
computer (SRS FR-1.1, FR-1.7). None of this is applied to development
machines — the dev/build environment runs the kiosk as an ordinary
windowed app. Apply these steps only on the actual shared student
account of a machine being deployed to a school.

**Steps 2-4 below are automated by `setup-kiosk-hardening.ps1`** in this
same folder — it creates the account, sets autologon, replaces its
shell, and disables Task Manager/Run/Control Panel for it. It has not
been tested on real hardware (none was available while writing it);
run it with `-DryRun` first and read what it prints before running for
real. Steps 1, 5, and the verification checklist in step 6 are still
manual — the script doesn't choose your password or check its own work.

The kiosk app itself only handles app-level behavior (fullscreen, no
window chrome, in-webview shortcut blocking — see `kioskGuards.ts` and
`tauri.kiosk.conf.json`). It cannot block Ctrl+Alt+Del, Task Manager, or
switching away from itself — that requires the steps below.

## 1. Dedicated shared local account

Create a low-privilege local Windows account used only for students
(e.g. `student`), separate from the technician/admin account used to
maintain the machine. Do not make it an administrator.

## 2. Autologon into the student account

Use Microsoft's **Autologon** (Sysinternals) or the registry values
under:

```
HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon
  AutoAdminLogon = 1
  DefaultUserName = student
  DefaultPassword = <account password>
  DefaultDomainName = <machine name>
```

Storing a plaintext password in the registry is a known tradeoff of
this approach — acceptable here because the shared account itself is
already low-privilege and low-trust by design (SRS §2.5).

## 3. Replace the shell for the student account only

Set a **per-user** shell override so only the student account launches
the kiosk app instead of `explorer.exe` (do not change the machine-wide
default, so the technician account still gets a normal desktop):

```
HKEY_USERS\<student-account-SID>\Software\Microsoft\Windows NT\CurrentVersion\Winlogon
  Shell = "C:\Program Files\TOHLens\kiosk.exe"
```

(The student account's SID can be found with `wmic useraccount get name,sid`
or `Get-LocalUser | Select Name, SID`.)

When the kiosk app calls `explorer.exe` to hand off control after a
successful login (SRS FR-1.5), it should launch it as a **child
process**, not replace itself — the kiosk process should keep running
in the background so it can reclaim control on logout.

## 4. Disable Task Manager and other escape hatches for the student account

Apply via Local Group Policy (`gpedit.msc`, if available) or the
per-user registry hive, scoped to the student account only:

```
HKEY_CURRENT_USER\Software\Microsoft\Windows\CurrentVersion\Policies\System
  DisableTaskMgr = 1

HKEY_CURRENT_USER\Software\Microsoft\Windows\CurrentVersion\Policies\Explorer
  NoRun = 1               ; disable Run dialog
  NoControlPanel = 1
  NoViewContextMenu = 0   ; keep as needed; evaluate per deployment
```

Also disable known key combos at the policy level where possible
(Win+X, Win+R) — full suppression of Ctrl+Alt+Del is not possible from
user-mode software on Windows by design; rely on account restrictions
(no admin rights, Task Manager disabled) rather than trying to trap the
key combo itself.

## 5. Lock down USB/removable media and other local attack surface (optional, evaluate per school)

Consider Group Policy restrictions on removable storage and installing
new software, consistent with the account being shared and low-trust.

## 6. Verification checklist before shipping a machine

- [ ] Machine boots directly into the kiosk keypad screen with no
      visible Windows login prompt.
- [ ] Task Manager cannot be opened via any of Ctrl+Shift+Esc,
      Ctrl+Alt+Del menu, or right-click on the taskbar.
- [ ] Alt+Tab / Win key do not expose a usable desktop before login.
- [ ] After a successful kiosk login, the normal desktop/apps become
      usable for lessons.
- [ ] After Log Out, the machine returns cleanly to the kiosk keypad,
      not to a Windows desktop or lock screen.
- [ ] The technician account (separate from `student`) still logs in
      normally for maintenance.
