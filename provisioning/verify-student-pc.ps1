<#
.SYNOPSIS
    Checks that a student computer is ready for TOH Klas, and says what is wrong if not.

.DESCRIPTION
    Read-only: it changes nothing. Prints PASS / WARN / FAIL for each check and exits
    with 1 if anything FAILed, so it can be used in a rollout script.

    Run it on the student computer after provisioning and the first student-account
    login. Run it in an elevated PowerShell to be able to read another account's
    kiosk log; without that some checks say "could not read".

    It covers what can be checked from the outside: Windows can capture the screen,
    the kiosk and browser integration are installed, the server and the screen-sharing
    connection service are reachable, the clocks agree, and the kiosk log has no
    recent failures. Being enrolled is confirmed in the Teacher app (Fleet panel).

.PARAMETER BackendUrl
    The TOH Klas server address. Enables the server and clock checks.

.PARAMETER ExtensionId
    The Chrome/Edge extension id. Enables the check that the native host trusts it.

.PARAMETER StudentUsername
    The student Windows account whose kiosk data to inspect. Default: student.

.EXAMPLE
    .\verify-student-pc.ps1 -BackendUrl https://klas.school.example -ExtensionId ifialcnolohnhdlojcngcdgiglgffeih
#>

[CmdletBinding()]
param(
    [string]$BackendUrl,
    [ValidatePattern('^[a-p]{32}$')]
    [string]$ExtensionId,
    [string]$StudentUsername = "student"
)

$ErrorActionPreference = "Continue"
$script:Results = New-Object System.Collections.Generic.List[object]

function Add-Result {
    param([ValidateSet("PASS", "WARN", "FAIL")][string]$Status, [string]$What, [string]$Detail = "")
    $script:Results.Add([pscustomobject]@{ Status = $Status; What = $What; Detail = $Detail })
}

$HostName = "com.techonhand.klas"
$KioskExe = Join-Path $env:ProgramFiles "TOH Klas Student\kiosk.exe"
$HostExe = Join-Path $env:ProgramFiles "TOH Klas\native-host\toh-klas-native-host.exe"
$HostManifest = Join-Path $env:ProgramFiles "TOH Klas\native-host\$HostName.json"
$StudentProfile = Join-Path (Split-Path $env:USERPROFILE -Parent) $StudentUsername

# --- Windows ------------------------------------------------------------------------
$os = Get-CimInstance Win32_OperatingSystem
$build = [int]$os.BuildNumber
Add-Result "PASS" "Windows" "$($os.Caption), build $build"

if ($build -lt 18362) {
    Add-Result "FAIL" "Screen capture" "Windows build $build is too old for screen watch (needs 18362, Windows 10 1903, or newer)."
} else {
    $supported = $null
    $borderToggle = $null
    try {
        # Only Windows PowerShell 5.1 can load Windows Runtime types like this.
        [void][Windows.Graphics.Capture.GraphicsCaptureSession, Windows.Graphics.Capture, ContentType = WindowsRuntime]
        [void][Windows.Foundation.Metadata.ApiInformation, Windows.Foundation.UniversalApiContract, ContentType = WindowsRuntime]
        $supported = [Windows.Graphics.Capture.GraphicsCaptureSession]::IsSupported()
        $borderToggle = [Windows.Foundation.Metadata.ApiInformation]::IsPropertyPresent("Windows.Graphics.Capture.GraphicsCaptureSession", "IsBorderRequired")
    } catch {
        # Fall through: build number only.
    }
    if ($supported -eq $false) {
        Add-Result "FAIL" "Screen capture" "Windows reports that the Graphics Capture API is not supported on this computer."
    } elseif ($supported -eq $true) {
        Add-Result "PASS" "Screen capture" "supported"
        if (-not $borderToggle) {
            Add-Result "WARN" "Capture border" "This Windows build cannot hide the yellow capture border, so students will see it while a teacher watches. Cosmetic only; watching still works."
        }
    } else {
        Add-Result "PASS" "Screen capture" "build $build is new enough (run in Windows PowerShell 5.1 for a full check)"
    }
}

$webView = Get-ItemProperty "HKLM:\SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}" -ErrorAction SilentlyContinue
if ($webView -and $webView.pv) { Add-Result "PASS" "WebView2 runtime" "version $($webView.pv)" }
else { Add-Result "FAIL" "WebView2 runtime" "not installed: the kiosk cannot show its screens. Install the Evergreen runtime from Microsoft." }

$free = [math]::Round((Get-PSDrive -Name ($env:SystemDrive.TrimEnd(':')) ).Free / 1GB, 1)
if ($free -lt 5) { Add-Result "WARN" "Disk space" "$free GB free on $env:SystemDrive" } else { Add-Result "PASS" "Disk space" "$free GB free" }

# --- kiosk ------------------------------------------------------------------------------
if (Test-Path $KioskExe) {
    Add-Result "PASS" "Kiosk installed" "$KioskExe (version $((Get-Item $KioskExe).VersionInfo.ProductVersion))"
} else {
    Add-Result "FAIL" "Kiosk installed" "$KioskExe not found: run provision-student-pc.ps1"
}

if (Get-Process -Name kiosk -ErrorAction SilentlyContinue) { Add-Result "PASS" "Kiosk running" "" }
else { Add-Result "WARN" "Kiosk running" "not running right now (it starts when the student account signs in)" }

$provisioning = Join-Path $env:ProgramData "TOH Klas\provisioning.json"
if (Test-Path $provisioning) {
    Add-Result "WARN" "Enrollment" "provisioning.json is still here: the kiosk has not enrolled yet (nobody has signed in as $StudentUsername), or enrolling failed. See the kiosk log below."
} else {
    Add-Result "PASS" "Enrollment" "no pending enrollment file (enrolled, or never provisioned this way): confirm it in the Teacher app"
}

# --- kiosk log ---------------------------------------------------------------------------
$logDir = Join-Path $StudentProfile "AppData\Local\com.techonhand.kiosk\logs"
$log = Get-ChildItem $logDir -Filter *.log -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if (-not $log) {
    Add-Result "WARN" "Kiosk log" "no log at $logDir (the kiosk has not run as $StudentUsername yet, or it could not be read)"
} else {
    $problems = @(Get-Content $log.FullName -Tail 300 -ErrorAction SilentlyContinue | Where-Object { $_ -match "\[kiosk_lib::" -and $_ -match "\[(WARN|ERROR)\]" })
    if ($problems.Count -eq 0) { Add-Result "PASS" "Kiosk log" "no recent warnings from the kiosk" }
    else { Add-Result "WARN" "Kiosk log" "$($problems.Count) recent warning(s); latest: $($problems[-1].Trim())" }
}

$bridge = Join-Path $StudentProfile "AppData\Local\TOH Klas\bridge.json"
if (Test-Path $bridge) { Add-Result "PASS" "Agent bridge" "the kiosk has published its browser bridge" }
else { Add-Result "WARN" "Agent bridge" "$bridge not found: the kiosk has not run as $StudentUsername, so the browser extension has nothing to talk to" }

# --- browser integration -----------------------------------------------------------------------
if (Test-Path $HostExe) { Add-Result "PASS" "Native host" $HostExe } else { Add-Result "FAIL" "Native host" "$HostExe not found: run install-browser-integration.ps1" }

foreach ($browser in @(@{ Name = "Chrome"; Vendor = "Google\Chrome"; Private = "IncognitoModeAvailability"; Policies = "Google\Chrome" },
                       @{ Name = "Edge"; Vendor = "Microsoft\Edge"; Private = "InPrivateModeAvailability"; Policies = "Microsoft\Edge" })) {
    $hostKey = "HKLM:\SOFTWARE\$($browser.Vendor)\NativeMessagingHosts\$HostName"
    $registered = (Get-ItemProperty $hostKey -ErrorAction SilentlyContinue).'(default)'
    if ($registered) { Add-Result "PASS" "$($browser.Name) native host registered" "" }
    else { Add-Result "WARN" "$($browser.Name) native host registered" "not registered (fine if $($browser.Name) is not used on this computer)"; continue }

    $policy = Get-ItemProperty "HKLM:\SOFTWARE\Policies\$($browser.Policies)" -ErrorAction SilentlyContinue
    if ($policy.($browser.Private) -eq 1) { Add-Result "PASS" "$($browser.Name) private windows" "disabled by policy" }
    else { Add-Result "WARN" "$($browser.Name) private windows" "still allowed: students can browse without being seen or restricted" }
}

if ($ExtensionId -and (Test-Path $HostManifest)) {
    if ((Get-Content $HostManifest -Raw) -match [regex]::Escape($ExtensionId)) { Add-Result "PASS" "Extension trusted" "the native host accepts $ExtensionId" }
    else { Add-Result "FAIL" "Extension trusted" "$HostManifest does not list $ExtensionId in allowed_origins: re-run install-browser-integration.ps1 with this id" }
}

# --- network ------------------------------------------------------------------------------------
if ($BackendUrl) {
    $BackendUrl = $BackendUrl.Trim().TrimEnd('/')
    try {
        $timer = [System.Diagnostics.Stopwatch]::StartNew()
        $response = Invoke-WebRequest -Uri "$BackendUrl/api/ping" -UseBasicParsing -TimeoutSec 8
        $timer.Stop()
        Add-Result "PASS" "Server reachable" "$BackendUrl answered in $($timer.ElapsedMilliseconds) ms"
        if ($BackendUrl -notmatch '^https://') { Add-Result "WARN" "Server encryption" "$BackendUrl is not https" }

        $serverDate = $response.Headers["Date"]
        if ($serverDate) {
            $skew = [math]::Abs(([datetime]::Parse($serverDate).ToUniversalTime() - [datetime]::UtcNow).TotalSeconds)
            if ($skew -gt 120) { Add-Result "WARN" "Clock" "this computer's clock differs from the server's by $([math]::Round($skew)) seconds: fix the time or session times will look wrong" }
            else { Add-Result "PASS" "Clock" "within $([math]::Round($skew)) s of the server" }
        }
    } catch {
        Add-Result "FAIL" "Server reachable" "$BackendUrl/api/ping failed: $($_.Exception.Message)"
    }
} else {
    Add-Result "WARN" "Server reachable" "skipped: pass -BackendUrl to check it"
}

# A STUN binding request: a UDP round trip to Google's public STUN server, which is what
# screen watch and broadcast need to find each computer's real address. UDP can lose a
# single packet, so it tries a few times and on two of Google's hosts before giving up.
function Test-Stun {
    param([string]$HostName)
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        $udp = New-Object System.Net.Sockets.UdpClient
        try {
            $udp.Client.ReceiveTimeout = 2000
            $request = New-Object byte[] 20
            $request[0] = 0x00; $request[1] = 0x01; $request[4] = 0x21; $request[5] = 0x12; $request[6] = 0xA4; $request[7] = 0x42
            $id = New-Object byte[] 12
            (New-Object System.Random).NextBytes($id)
            [Array]::Copy($id, 0, $request, 8, 12)
            [void]$udp.Send($request, 20, $HostName, 19302)
            $remote = New-Object System.Net.IPEndPoint([System.Net.IPAddress]::Any, 0)
            $reply = $udp.Receive([ref]$remote)
            if ($reply.Length -ge 20 -and $reply[0] -eq 0x01 -and $reply[1] -eq 0x01) { return $true }
        } catch {
            # No reply this time.
        } finally {
            $udp.Close()
        }
    }
    return $false
}

$stunHost = @("stun.l.google.com", "stun1.l.google.com") | Where-Object { Test-Stun $_ } | Select-Object -First 1
if ($stunHost) { Add-Result "PASS" "Screen-sharing connection service" "$stunHost answered" }
else { Add-Result "WARN" "Screen-sharing connection service" "no answer from Google's STUN servers over UDP (port 19302): screen watch and broadcast between computers will not connect on this network. Student PCs otherwise work normally." }

# --- report --------------------------------------------------------------------------------------
Write-Host ""
foreach ($result in $script:Results) {
    $colour = @{ PASS = "Green"; WARN = "Yellow"; FAIL = "Red" }[$result.Status]
    Write-Host ("  {0}  {1}" -f $result.Status, $result.What) -ForegroundColor $colour -NoNewline
    if ($result.Detail) { Write-Host " - $($result.Detail)" } else { Write-Host "" }
}
$fails = @($script:Results | Where-Object Status -eq "FAIL").Count
$warns = @($script:Results | Where-Object Status -eq "WARN").Count
Write-Host ""
if ($fails -gt 0) { Write-Host "$fails check(s) FAILED, $warns warning(s). Fix the FAIL items first." -ForegroundColor Red; exit 1 }
Write-Host "Nothing failed. $warns warning(s) to read." -ForegroundColor Green
exit 0
