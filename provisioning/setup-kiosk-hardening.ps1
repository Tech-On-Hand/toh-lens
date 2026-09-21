<#
.SYNOPSIS
    Automates steps 2-4 of provisioning/windows-kiosk-hardening.md: creates
    the shared student account, sets autologon, replaces its shell with the
    kiosk app, and disables Task Manager / Run / Control Panel for it.

.DESCRIPTION
    NOT YET TESTED ON REAL HARDWARE. This was written by reasoning through
    the Windows APIs involved, not verified against an actual machine —
    there was no hardened kiosk PC available at the time it was written.
    Run it with -DryRun first, read every line it prints, and go through
    the full checklist in windows-kiosk-hardening.md §6 afterwards. Keep a
    working admin/technician login on the machine before running this —
    a mistake in autologon/shell settings can make the student account
    hard to log into normally (recoverable via Safe Mode, but avoid it).

    Steps NOT covered here, still manual:
      - Step 1: choosing the account and a strong password (you supply both).
      - Step 5: optional USB/removable-media lockdown (school-specific).
      - Step 6: the verification checklist — nothing here checks its own work.

    The riskiest part is forcing the student account's Windows profile to
    exist before it's ever logged into interactively, so its per-user
    registry hive can be edited. This uses Start-Process -Credential as a
    trigger, which is known to fail in some environments (e.g. missing
    "Log on locally" rights, certain domain policies). If that happens,
    the script stops with instructions rather than guessing further.

.PARAMETER StudentUsername
    The shared local account name for students. Default: "student".

.PARAMETER StudentPassword
    A SecureString password for that account. Required — pass it as
    (Read-Host -AsSecureString) rather than a plaintext -Parameter, since
    PowerShell history can otherwise leak it.

.PARAMETER KioskExePath
    Full path to the installed kiosk.exe. Default:
    "C:\Program Files\TOHLens\kiosk.exe".

.PARAMETER DryRun
    Print every action without changing anything. Always run this first.

.EXAMPLE
    $pw = Read-Host -AsSecureString "Student account password"
    .\setup-kiosk-hardening.ps1 -StudentPassword $pw -DryRun

.EXAMPLE
    $pw = Read-Host -AsSecureString "Student account password"
    .\setup-kiosk-hardening.ps1 -StudentPassword $pw
#>

[CmdletBinding()]
param(
    [string]$StudentUsername = "student",

    [Parameter(Mandatory = $true)]
    [System.Security.SecureString]$StudentPassword,

    [string]$KioskExePath = "C:\Program Files\TOHLens\kiosk.exe",

    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

function Write-Step {
    param([string]$Message)
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Invoke-Action {
    param([string]$Description, [scriptblock]$Action)
    if ($DryRun) {
        Write-Host "  [dry run] would: $Description" -ForegroundColor DarkYellow
        return
    }
    Write-Host "  $Description"
    & $Action
}

# --- Preconditions -----------------------------------------------------

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltinRole]::Administrator)
if (-not $isAdmin) {
    Write-Error "Run this from an elevated (Administrator) PowerShell window."
    exit 1
}

if ($DryRun) {
    Write-Host "DRY RUN — no changes will be made.`n" -ForegroundColor Yellow
}

# --- Step 2 (partial) / prerequisite: the local account ----------------

Write-Step "Ensuring the '$StudentUsername' local account exists"

$existingUser = Get-LocalUser -Name $StudentUsername -ErrorAction SilentlyContinue
if ($existingUser) {
    Write-Host "  Account already exists — leaving it as-is (password unchanged)."
} else {
    Invoke-Action "create local user '$StudentUsername' (not an administrator)" {
        New-LocalUser -Name $StudentUsername -Password $StudentPassword `
            -PasswordNeverExpires -UserMayNotChangePassword `
            -Description "TOH Lens kiosk shared student account" | Out-Null
        Add-LocalGroupMember -Group "Users" -Member $StudentUsername -ErrorAction SilentlyContinue
    }
}

# --- Step 2: autologon ---------------------------------------------------

Write-Step "Configuring autologon into '$StudentUsername'"

# The registry has no secure string storage — this plaintext password in
# HKLM is a known, accepted tradeoff (see windows-kiosk-hardening.md §2):
# the account itself is already low-privilege and low-trust by design.
# The BSTR is still zeroed and freed as soon as we're done with it, so it
# doesn't linger in process memory any longer than necessary.
$bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($StudentPassword)
try {
    $plainPassword = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr)
} finally {
    [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
}

$winlogonPath = "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon"
Invoke-Action "set AutoAdminLogon=1, DefaultUserName=$StudentUsername, DefaultDomainName=$env:COMPUTERNAME" {
    Set-ItemProperty -Path $winlogonPath -Name "AutoAdminLogon" -Value "1"
    Set-ItemProperty -Path $winlogonPath -Name "DefaultUserName" -Value $StudentUsername
    Set-ItemProperty -Path $winlogonPath -Name "DefaultPassword" -Value $plainPassword
    Set-ItemProperty -Path $winlogonPath -Name "DefaultDomainName" -Value $env:COMPUTERNAME
}

# --- Force the student profile to exist ---------------------------------
# Steps 3-4 edit the student account's OWN registry hive (HKEY_USERS\<SID>),
# which Windows only creates on first interactive logon. We don't want to
# wait for that, so we trigger profile creation now via a throwaway
# credentialed process. This is the step most likely to need a manual
# fallback — see the warning below if it fails.

Write-Step "Getting the student account's SID and profile path"

$studentSid = if (-not $DryRun) { (Get-LocalUser -Name $StudentUsername).SID.Value } else { "S-1-5-21-...-DRYRUN" }
$profilePath = "C:\Users\$StudentUsername"
$ntUserDatPath = Join-Path $profilePath "NTUSER.DAT"

Write-Host "  SID: $studentSid"
Write-Host "  Profile: $profilePath"

$profileExists = Test-Path $ntUserDatPath

if (-not $profileExists) {
    Write-Step "Forcing profile creation (no interactive logon has happened yet)"
    Invoke-Action "run a trivial process as $StudentUsername to trigger Windows profile creation" {
        $cred = New-Object System.Management.Automation.PSCredential($StudentUsername, $StudentPassword)
        try {
            Start-Process -FilePath "$env:WINDIR\System32\whoami.exe" `
                -Credential $cred -WorkingDirectory "$env:WINDIR\System32" `
                -WindowStyle Hidden -Wait -ErrorAction Stop
            Start-Sleep -Seconds 2
        } catch {
            Write-Warning @"
Could not auto-create the student profile ($($_.Exception.Message)).
This can happen if '$StudentUsername' lacks "Log on locally" rights, or
this environment blocks credentialed process creation.

Fallback: log in as '$StudentUsername' once manually (Switch User, or
physically log in and back out), then re-run this script — it will
detect the existing profile and skip this step.
"@
            exit 1
        }
    }

    if (-not $DryRun -and -not (Test-Path $ntUserDatPath)) {
        Write-Error "Profile still doesn't exist after the forced logon attempt. Log in as '$StudentUsername' manually once, then re-run this script."
        exit 1
    }
}

# --- Steps 3 & 4: per-user shell + lockdown policies --------------------

Write-Step "Setting the per-user shell and lockdown policies for '$StudentUsername'"

$hiveLoaded = $false
$userRegRoot = "Registry::HKEY_USERS\$studentSid"

if (-not $DryRun -and -not (Test-Path $userRegRoot)) {
    Invoke-Action "load $ntUserDatPath into HKU\TOHLensKioskHive (account isn't currently logged in)" {
        reg load "HKU\TOHLensKioskHive" $ntUserDatPath | Out-Null
    }
    $userRegRoot = "Registry::HKEY_USERS\TOHLensKioskHive"
    $hiveLoaded = $true
} elseif ($DryRun) {
    $hiveLoaded = $true # so the dry run also prints the unload step below
}

$shellPath = "$userRegRoot\Software\Microsoft\Windows NT\CurrentVersion\Winlogon"
Invoke-Action "set Shell=$KioskExePath at $shellPath" {
    New-Item -Path $shellPath -Force | Out-Null
    Set-ItemProperty -Path $shellPath -Name "Shell" -Value $KioskExePath
}

$systemPoliciesPath = "$userRegRoot\Software\Microsoft\Windows\CurrentVersion\Policies\System"
Invoke-Action "set DisableTaskMgr=1 at $systemPoliciesPath" {
    New-Item -Path $systemPoliciesPath -Force | Out-Null
    Set-ItemProperty -Path $systemPoliciesPath -Name "DisableTaskMgr" -Value 1 -Type DWord
}

$explorerPoliciesPath = "$userRegRoot\Software\Microsoft\Windows\CurrentVersion\Policies\Explorer"
Invoke-Action "set NoRun=1, NoControlPanel=1 at $explorerPoliciesPath" {
    New-Item -Path $explorerPoliciesPath -Force | Out-Null
    Set-ItemProperty -Path $explorerPoliciesPath -Name "NoRun" -Value 1 -Type DWord
    Set-ItemProperty -Path $explorerPoliciesPath -Name "NoControlPanel" -Value 1 -Type DWord
}

if ($hiveLoaded) {
    Invoke-Action "unload HKU\TOHLensKioskHive" {
        # PowerShell can hold file handles into the hive via the Registry::
        # provider; garbage-collect and pause before unloading, or "reg
        # unload" fails with Access Denied. If it still fails, a restart
        # releases the handle — re-run the script afterwards to confirm.
        [gc]::Collect()
        [gc]::WaitForPendingFinalizers()
        Start-Sleep -Milliseconds 500
        reg unload "HKU\TOHLensKioskHive" | Out-Null
    }
}

# --- Summary -------------------------------------------------------------

Write-Host ""
Write-Step "Done. Now work through windows-kiosk-hardening.md #6 by hand:"
Write-Host @"
  - Reboot and confirm it boots straight into the kiosk keypad, no visible
    Windows login prompt.
  - Confirm Task Manager cannot be opened (Ctrl+Shift+Esc, Ctrl+Alt+Del
    menu, taskbar right-click).
  - Confirm a successful kiosk login hands off to a normal usable desktop,
    and Log Out returns cleanly to the keypad.
  - Confirm your own technician/admin account still logs in normally.
"@

if ($DryRun) {
    Write-Host "`nThis was a dry run — nothing was actually changed." -ForegroundColor Yellow
}
