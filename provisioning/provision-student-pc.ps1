<#
.SYNOPSIS
    Sets up one student computer for TOH Klas in a single run: installs the kiosk,
    leaves it an enrollment file so it enrolls itself, installs the browser
    integration and (optionally) applies the kiosk hardening.

.DESCRIPTION
    Run from an ELEVATED PowerShell on the student computer, from a folder that has:
      - the kiosk installer (.msi, built by `npm run tauri:build:kiosk` in kiosk/)
      - toh-klas-native-host.exe (built by `cargo build --release` in browser-host/)
      - install-browser-integration.ps1 and setup-kiosk-hardening.ps1 (this folder)

    What it does, in order:
      1. Installs the kiosk MSI silently (per machine, C:\Program Files\TOH Klas Student).
      2. Writes %ProgramData%\TOH Klas\provisioning.json with the server address, the
         one-time enrollment code and the device name. The kiosk reads it the first
         time it starts on the student account, enrolls itself and deletes the file.
      3. Installs the native messaging host and the Chrome/Edge policies
         (install-browser-integration.ps1).
      4. Only if -StudentPassword is given: creates the shared student account and
         locks the machine down (setup-kiosk-hardening.ps1). Read that script's
         warnings first; it has not been proven on real hardware.

    Enrollment happens when the kiosk first RUNS as the student, not now, so the
    computer appears in the Teacher app after the first student-account login. Run
    verify-student-pc.ps1 after that.

    The enrollment code is single-use and expires (issue codes with
    `php artisan klas:enrollment-codes`), but until the kiosk has used it the file is
    readable by any local user, so provision and sign in promptly.

.PARAMETER KioskMsi
    Path to the kiosk installer (.msi).

.PARAMETER BackendUrl
    Address of the TOH Klas server, e.g. https://klas.school.example

.PARAMETER EnrollmentCode
    A one-time code for this computer's classroom, e.g. AB12-CD34.

.PARAMETER DeviceName
    Name shown in the Teacher app. Default: this computer's name.

.PARAMETER ExtensionId
    Chrome/Edge extension id (32 letters a-p). Not needed with -SkipBrowser.

.PARAMETER HostExePath
    The built toh-klas-native-host.exe. Default: browser-host\target\release\ next to this folder.

.PARAMETER SkipForceInstall
    Register the native host but do not force-install the extension (for loading it unpacked while testing).

.PARAMETER SkipBrowser
    Skip step 3.

.PARAMETER StudentPassword
    SecureString password for the shared student account. Giving it turns on step 4.

.PARAMETER DryRun
    Print every action without changing anything. Always run this first.

.EXAMPLE
    .\provision-student-pc.ps1 -KioskMsi .\kiosk.msi -BackendUrl https://klas.school.example `
        -EnrollmentCode AB12-CD34 -DeviceName "Lab PC 01" -ExtensionId ifialcnolohnhdlojcngcdgiglgffeih `
        -HostExePath .\toh-klas-native-host.exe -SkipForceInstall -DryRun
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$KioskMsi,

    [Parameter(Mandatory = $true)]
    [string]$BackendUrl,

    [Parameter(Mandatory = $true)]
    [string]$EnrollmentCode,

    [string]$DeviceName = $env:COMPUTERNAME,

    [ValidatePattern('^[a-p]{32}$')]
    [string]$ExtensionId,

    [string]$HostExePath,
    [switch]$SkipForceInstall,
    [switch]$SkipBrowser,
    [System.Security.SecureString]$StudentPassword,
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

# --- checks before touching anything ------------------------------------------
Write-Step "Checking inputs"

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin -and -not $DryRun) {
    throw "Run this from an elevated PowerShell (Run as administrator)."
}
if (-not (Test-Path $KioskMsi)) { throw "Kiosk installer not found: $KioskMsi" }
$KioskMsi = (Resolve-Path $KioskMsi).Path

$BackendUrl = $BackendUrl.Trim().TrimEnd('/')
if ($BackendUrl -notmatch '^https?://') { throw "-BackendUrl must start with http:// or https://" }
if ($BackendUrl -match '^http://') {
    Write-Warning "$BackendUrl is not https: the device token and student data will cross the network unencrypted."
}
if ($BackendUrl -match '://(localhost|127\.0\.0\.1)') {
    Write-Warning "$BackendUrl points at this computer itself; a student PC needs the server's real address."
}

if (-not $SkipBrowser) {
    if (-not $ExtensionId) { throw "-ExtensionId is required unless -SkipBrowser is given." }
    if (-not $HostExePath) { $HostExePath = Join-Path $PSScriptRoot "../browser-host/target/release/toh-klas-native-host.exe" }
    if (-not (Test-Path $HostExePath)) { throw "Native host not found: $HostExePath (build it: cd browser-host; cargo build --release)" }
    $HostExePath = (Resolve-Path $HostExePath).Path
}

try {
    $ping = Invoke-WebRequest -Uri "$BackendUrl/api/ping" -UseBasicParsing -TimeoutSec 8
    Write-Host "  server reachable ($($ping.StatusCode))" -ForegroundColor Green
} catch {
    Write-Warning "Could not reach $BackendUrl/api/ping from this computer ($($_.Exception.Message)). Continuing: enrollment happens later, but check the address and the network."
}

$InstallDir = Join-Path $env:ProgramFiles "TOH Klas Student"
$KioskExe = Join-Path $InstallDir "kiosk.exe"
$ProvisioningDir = Join-Path $env:ProgramData "TOH Klas"
$ProvisioningFile = Join-Path $ProvisioningDir "provisioning.json"

# --- 1. kiosk ------------------------------------------------------------------
Write-Step "1/4 Installing the kiosk"
Invoke-Action "install $KioskMsi silently" {
    $log = Join-Path $env:TEMP "toh-klas-kiosk-install.log"
    $process = Start-Process msiexec.exe -ArgumentList @("/i", "`"$KioskMsi`"", "/qn", "/norestart", "/l*v", "`"$log`"") -Wait -PassThru
    # 3010 = installed, a restart is pending.
    if ($process.ExitCode -notin 0, 3010) { throw "The installer failed with exit code $($process.ExitCode). Log: $log" }
    if (-not (Test-Path $KioskExe)) { throw "Installed, but $KioskExe is missing. Log: $log" }
    Write-Host "  installed: $KioskExe"
}

# --- 2. enrollment file ----------------------------------------------------------
Write-Step "2/4 Leaving the enrollment file"
Invoke-Action "write $ProvisioningFile for device '$DeviceName'" {
    New-Item -ItemType Directory -Force -Path $ProvisioningDir | Out-Null
    $json = [ordered]@{ api_base_url = $BackendUrl; enrollment_code = $EnrollmentCode.Trim(); device_name = $DeviceName } | ConvertTo-Json
    # UTF-8 without a byte-order mark.
    [System.IO.File]::WriteAllText($ProvisioningFile, $json, (New-Object System.Text.UTF8Encoding($false)))
    # The kiosk runs as the student, and deletes the file once it has enrolled.
    & icacls.exe $ProvisioningDir /grant "*S-1-5-32-545:(OI)(CI)M" | Out-Null
}

# --- 3. browser integration --------------------------------------------------------
Write-Step "3/4 Browser integration"
if ($SkipBrowser) {
    Write-Host "  skipped (-SkipBrowser)"
} else {
    $arguments = @{ ExtensionId = $ExtensionId; HostExePath = $HostExePath }
    if ($SkipForceInstall) { $arguments.SkipForceInstall = $true }
    if ($DryRun) { $arguments.DryRun = $true }
    & (Join-Path $PSScriptRoot "install-browser-integration.ps1") @arguments
}

# --- 4. hardening -------------------------------------------------------------------
Write-Step "4/4 Kiosk hardening"
if (-not $StudentPassword) {
    Write-Host "  skipped (no -StudentPassword). Do it later with setup-kiosk-hardening.ps1 once the rest is proven." -ForegroundColor Yellow
} else {
    $arguments = @{ StudentPassword = $StudentPassword; KioskExePath = $KioskExe }
    if ($DryRun) { $arguments.DryRun = $true }
    & (Join-Path $PSScriptRoot "setup-kiosk-hardening.ps1") @arguments
}

Write-Host ""
if ($DryRun) {
    Write-Host "Dry run finished: nothing was changed." -ForegroundColor Yellow
} else {
    Write-Host "Done. Next:" -ForegroundColor Green
    Write-Host "  1. Sign in as the student account (or start '$KioskExe'). The kiosk enrolls itself on first start."
    Write-Host "  2. Check '$DeviceName' appears in the Teacher app (Fleet panel, for administrators)."
    Write-Host "  3. Run .\verify-student-pc.ps1 -BackendUrl $BackendUrl to confirm this computer is healthy."
}
