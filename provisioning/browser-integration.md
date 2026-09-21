# TOH Klas browser integration (Milestone 2)

Lets a teacher see the tab a student is looking at and open, change, or close pages on that student's computer during class. Chrome and Edge only.

```text
Extension (MV3) <-native messaging-> toh-klas-native-host.exe <-loopback TCP-> Student Agent <-HTTPS-> Laravel
```

- The **extension** reports a tab snapshot and navigation history and runs commands. Source: `browser-extension/`.
- The **native host** only frames and relays messages; it holds no logic. Source: `browser-host/`.
- The **Student Agent** (the kiosk app) listens on `127.0.0.1` at a random port published, with a per-launch secret, in `%LOCALAPPDATA%\TOH Klas\bridge.json`. It stamps everything with the student session that was open when it happened, queues it in SQLite while offline, and syncs it.

## What is collected

| Data | Kept | Notes |
|---|---|---|
| Open tabs: URL, title, which one is active | Latest snapshot only | Replaced on every change |
| Navigation history: URL, title, time | Until retention rules exist (Milestone 6) | Attributed to the student session |
| Private/Incognito windows | Never reported | See "Limits" |

URLs are stored in full, including query strings. Decide with the school whether that is acceptable before deployment, and tell students and parents; the extension description says what it does.

## Install on a student computer

1. Build the host: `cd browser-host; cargo build --release`
2. Get the extension ID. For development it is fixed by the key in `browser-extension/manifest.json`: `ifialcnolohnhdlojcngcdgiglgffeih`. For production generate your own key so you control the signing identity, and put its `key` into the manifest before publishing:
   `node browser-extension/tools/generate-key.mjs --out extension-key.pem`
3. From an elevated PowerShell:
   ```powershell
   .\provisioning\install-browser-integration.ps1 -ExtensionId <id> -DryRun   # read it
   .\provisioning\install-browser-integration.ps1 -ExtensionId <id>
   ```
4. Restart Chrome/Edge and open `chrome://policy` / `edge://policy`.

`-Uninstall` reverses it (it leaves the private/guest policies alone). `-SkipForceInstall` registers only the host, for loading the extension unpacked while testing.

### Force-install needs the store, or a managed device

Chrome and Edge ignore force-installed extensions hosted anywhere but their stores unless the device is domain-joined or otherwise managed. A standalone lab PC therefore needs the extension published to the Chrome Web Store and Edge Add-ons (an unlisted item is fine), which is what the script's default update URLs assume. If the store assigns a different ID than your manifest key, pass that ID to the script; it also pins the host manifest's `allowed_origins`, so only that extension can start the host.

## Automated end-to-end test

`browser-extension/e2e/browser-e2e.mjs` (run `npm run e2e` in `browser-extension/` after `cargo build --release` in `browser-host/`) starts a real headless Chrome or Edge with a throwaway profile, loads the real extension, and connects it through the real native host to a stand-in agent. It checks hello, snapshots, navigation history, open/navigate/close commands, refusal of `javascript:`/`file:`/`chrome:` URLs, wrong-browser commands, and automatic reconnection after the agent restarts. It adds one per-user registry key for the run and removes it afterwards. It passes on Chrome 153 and Edge 153.

To try the extension by hand, use `chrome://extensions` -> Developer mode -> Load unpacked. Current Chrome ignores the `--load-extension` command-line flag; the test loads the extension through the DevTools protocol instead.

`node e2e/full-stack.mjs` (repo root) covers the other link: the Student Agent's real bridge and sync code against a real Laravel server on a throwaway SQLite database. A fake extension reports tabs and a navigation, a teacher sees the active tab and issues a command, the agent polls and forwards it, the result comes back, and after sign-out the same student session is refused. It never touches your MySQL data or Windows Credential Manager.

## Verification checklist

The end-to-end test above covers the browser side. Nothing below has been run on a real school machine yet, and the parts that depend on the real Student Agent, the Teacher app, and Windows policy are untested.

- [ ] `chrome://policy` shows the extension under `ExtensionInstallForcelist` and the student cannot remove or disable it on `chrome://extensions`.
- [ ] Incognito / InPrivate and Guest mode are unavailable.
- [ ] With the agent running and a student signed in, the teacher app shows the student's active tab within a few seconds of navigating.
- [ ] Alt-tabbing to another program clears the active tab (the browser lost focus).
- [ ] Teacher "open URL" opens a tab on the student's screen; `javascript:`/`file:` URLs are refused.
- [ ] Sign the student out and another in: a command issued for the first student is refused, and the first student's browsing is not credited to the second.
- [ ] Unplug the network, browse, reconnect: the history arrives, credited to the right student.
- [ ] Kill the agent while the browser is open: the extension keeps retrying and recovers when the agent returns.

## Troubleshooting

- **Nothing reaches the teacher:** confirm `%LOCALAPPDATA%\TOH Klas\bridge.json` exists for the student's Windows account (the agent writes it at startup) and that the agent is running. The host exits immediately if it cannot read that file, and the extension retries with backoff up to 30 seconds.
- **`chrome://extensions` shows "Native host has exited" / "Specified native messaging host not found":** the registry key or the host manifest path is wrong, or the extension ID does not match `allowed_origins`.
- **Host works in a test but not in the browser:** the host must run as the same Windows account as the agent, because the bridge file lives in that account's `%LOCALAPPDATA%`.

## Blocked sites and focus sessions (Milestone 3)

Teachers can block sites for a classroom (school administrators can block them school-wide) and start a **focus session**: only the listed sites work, on every computer in the classroom, until the time is up or the teacher ends it. Blocked sites stay blocked during focus. The Teacher app has a "Focus & blocked sites" panel; the API is described in `toh-klas-contracts.md`.

How it behaves:

- **Enforced by the browser, not the network.** The extension installs `declarativeNetRequest` rules for top-level page navigations. They keep working while the extension's worker is asleep and while the Student Agent or the network is unavailable. A blocked page shows a plain explanation and a "Go back" button.
- **Reversible.** Tabs already open on a newly blocked site are moved to the blocked page, and returned to the address they left when the rule is lifted or focus ends.
- **Deadlines use the server's clock.** The agent converts each focus deadline to this computer's clock using the server's "now", so a wrong PC clock cannot extend or shorten a session. Focus ends on its own at the deadline even when offline; the agent checks every 3 seconds and the extension has its own timer plus a 30-second backup alarm.
- **Audited.** Focus start/end/expiry, rule changes, browser commands (with the student's session), and device changes are recorded in an append-only log, readable by school administrators through `GET /api/v1/admin/audit`. There is no screen for it yet. Blocked attempts are stored with the student's browsing history.
- **New permission.** Enforcement needs the extension to have access to all websites (`host_permissions`). The Chrome Web Store and Edge Add-ons review this closely, so be ready to explain it. The extension description tells students what it does.

What it does not do:

- **Only page navigations are policed.** Images, scripts and embedded frames on an allowed page still load, so an allowed site that embeds or proxies other content can lead elsewhere. Do not allow translation, proxy, cache or VPN sites, and consider blocking them.
- **Domain rules only.** A rule covers a domain and its subdomains. It does not match paths, keywords, or IP addresses, and non-ASCII domains must be entered in their `xn--` form.
- **A teacher's "open page" command is also subject to focus.** Opening a site outside the allow-list during focus lands on the blocked page.
- **Other browsers, private windows, and anything that bypasses the extension are not covered** (see Limits). Stronger enforcement at the Windows or network level is deliberately not part of this milestone and would need its own review.

## Limits

- **The student is the local adversary and shares the agent's Windows account.** Anything the agent can read, so can the student, including `bridge.json`. The bridge stops web pages and other machines, not a determined student with local access. Real protection is the kiosk hardening (no Task Manager, no shell, restricted programs) and, later, Milestone 3 enforcement.
- **Other browsers are not covered.** A student who can start Firefox or another browser is unmonitored. Restrict which programs can run, or block them.
- **Private windows are invisible by design**, so the script disables them by policy. Without that policy they are an easy bypass.
- **Delivery is at-most-once.** If the agent or browser crashes mid-command, the command expires and the teacher re-issues it; it never runs twice.
- **Policy and commands take a few seconds.** The agent polls about every 3 seconds while a student is signed in and the extension is connected. A push nudge over Reverb is reserved for later.
