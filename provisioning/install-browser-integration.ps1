<#
.SYNOPSIS
    Installs the TOH Klas browser integration on a student computer: copies the
    native messaging host, registers it for Chrome and Edge, force-installs the
    browser extension, and (optionally) disables private/guest browsing.

.DESCRIPTION
    NOT YET TESTED ON REAL HARDWARE. Written from the Chrome/Edge enterprise
    policy and native messaging documentation; only its file-writing half has
    been exercised (-SkipRegistry). Run with -DryRun first and read every line.
    Requires an elevated PowerShell because it writes to HKLM.

    What it changes (all machine-wide, which is intended for a dedicated lab PC):
      1. Copies toh-klas-native-host.exe to <InstallDir>\native-host\ and writes
         the host manifest there, allowing ONLY the given extension ID to start it.
      2. Points HKLM\...\Google\Chrome and ...\Microsoft\Edge NativeMessagingHosts
         at that manifest.
      3. Adds the extension to each browser's ExtensionInstallForcelist policy so
         students cannot disable or remove it.
      4. Unless -SkipHardening: disables Incognito / InPrivate and Guest mode,
         since private windows are invisible to the extension by design and
         would otherwise be a trivial way around monitoring.

    IMPORTANT - force-install source: Chrome and Edge only honor force-install
    from a self-hosted CRX on domain-joined or otherwise managed devices. On a
    standalone lab PC the extension must come from the Chrome Web Store / Edge
    Add-ons (the default update URLs below), e.g. as an unlisted item. See
    provisioning/browser-integration.md.

.PARAMETER ExtensionId
    The 32-letter (a-p) extension ID. Must match the key in
    browser-extension/manifest.json (or the store-assigned ID).

.PARAMETER HostExePath
    The built native host. Default: browser-host\target\release\toh-klas-native-host.exe.

.PARAMETER InstallDir
    Default: "C:\Program Files\TOH Klas".

.PARAMETER SkipForceInstall
    Register the host but do not touch the ExtensionInstallForcelist (use when a
    technician loads the extension unpacked for testing).

.PARAMETER SkipHardening
    Do not disable Incognito/InPrivate/Guest mode.

.PARAMETER SkipRegistry
    Only write files; make no registry changes (for testing the file half).

.PARAMETER Uninstall
    Reverse everything this script did.

.PARAMETER DryRun
    Print every action without changing anything.

.EXAMPLE
    .\install-browser-integration.ps1 -ExtensionId ifialcnolohnhdlojcngcdgiglgffeih -DryRun
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[a-p]{32}$')]
    [string]$ExtensionId,

    [string]$HostExePath,
    [string]$InstallDir,
    [string]$ChromeUpdateUrl = "https://clients2.google.com/service/update2/crx",
    [string]$EdgeUpdateUrl = "https://edge.microsoft.com/extensionwebstorebase/v1/crx",

    [switch]$SkipForceInstall,
    [switch]$SkipHardening,
    [switch]$SkipRegistry,
    [switch]$Uninstall,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

if (-not $HostExePath) {
    $HostExePath = Join-Path $PSScriptRoot "../browser-host/target/release/toh-klas-native-host.exe"
}
if (-not $InstallDir) {
    $InstallDir = Join-Path $env:ProgramFiles "TOH Klas"
}

$HostName = "com.techonhand.klas"
$HostDir = Join-Path $InstallDir "native-host"
$HostExe = Join-Path $HostDir "toh-klas-native-host.exe"
$ManifestPath = Join-Path $HostDir "$HostName.json"

$Browsers = @(
    @{
        Name          = "Chrome"
        HostKey       = "HKLM:\SOFTWARE\Google\Chrome\NativeMessagingHosts\$HostName"
        PolicyKey     = "HKLM:\SOFTWARE\Policies\Google\Chrome"
        UpdateUrl     = $ChromeUpdateUrl
        PrivateModeName = "IncognitoModeAvailability"
    },
    @{
        Name          = "Edge"
        HostKey       = "HKLM:\SOFTWARE\Microsoft\Edge\NativeMessagingHosts\$HostName"
        PolicyKey     = "HKLM:\SOFTWARE\Policies\Microsoft\Edge"
        UpdateUrl     = $EdgeUpdateUrl
        PrivateModeName = "InPrivateModeAvailability"
    }
)

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

function Set-RegistryValue {
    param([string]$Path, [string]$Name, $Value, [string]$Type = "String")
    if (-not (Test-Path $Path)) { New-Item -Path $Path -Force | Out-Null }
    New-ItemProperty -Path $Path -Name $Name -Value $Value -PropertyType $Type -Force | Out-Null
}

# Force-install entries are numbered values under the ExtensionInstallForcelist
# key. Reuse the slot that already holds this extension, otherwise take the
# next free number, so re-running the script never duplicates the entry.
function Set-ForceInstall {
    param([string]$PolicyKey, [string]$Entry)
    $listKey = Join-Path $PolicyKey "ExtensionInstallForcelist"
    if (-not (Test-Path $listKey)) { New-Item -Path $listKey -Force | Out-Null }

    $properties = Get-ItemProperty -Path $listKey
    $names = $properties.PSObject.Properties | Where-Object { $_.Name -match '^\d+$' }
    $existing = $names | Where-Object { "$($_.Value)" -like "$ExtensionId;*" } | Select-Object -First 1
    $slot = if ($existing) { $existing.Name } else {
        $used = @($names | ForEach-Object { [int]$_.Name })
        $next = 1
        while ($used -contains $next) { $next++ }
        "$next"
    }
    Set-RegistryValue -Path $listKey -Name $slot -Value $Entry
}

function Remove-ForceInstall {
    param([string]$PolicyKey)
    $listKey = Join-Path $PolicyKey "ExtensionInstallForcelist"
    if (-not (Test-Path $listKey)) { return }
    $properties = Get-ItemProperty -Path $listKey
    $properties.PSObject.Properties |
        Where-Object { $_.Name -match '^\d+$' -and "$($_.Value)" -like "$ExtensionId;*" } |
        ForEach-Object { Remove-ItemProperty -Path $listKey -Name $_.Name }
}

function New-HostManifest {
    [ordered]@{
        name            = $HostName
        description     = "TOH Klas browser integration"
        path            = $HostExe
        type            = "stdio"
        allowed_origins = @("chrome-extension://$ExtensionId/")
    } | ConvertTo-Json
}

if ($Uninstall) {
    Write-Step "Removing the TOH Klas browser integration"
    if (-not $SkipRegistry) {
        foreach ($browser in $Browsers) {
            Invoke-Action "remove $($browser.HostKey)" {
                Remove-Item -Path $browser.HostKey -Recurse -Force -ErrorAction SilentlyContinue
            }
            Invoke-Action "remove the $($browser.Name) force-install entry for $ExtensionId" {
                Remove-ForceInstall -PolicyKey $browser.PolicyKey
            }
        }
    }
    Invoke-Action "delete $HostDir" { Remove-Item -Path $HostDir -Recurse -Force -ErrorAction SilentlyContinue }
    Write-Host "Done. Private/guest browsing policies were left as they are; remove them by hand if unwanted." -ForegroundColor Green
    return
}

if (-not $DryRun -and -not (Test-Path $HostExePath)) {
    throw "Native host not found at '$HostExePath'. Build it first: cd browser-host; cargo build --release"
}

if (-not $SkipRegistry -and -not $DryRun) {
    $principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw "Run this from an elevated PowerShell (it writes to HKLM), or use -SkipRegistry / -DryRun."
    }
}

Write-Step "Installing the native messaging host"
Invoke-Action "create $HostDir" { New-Item -ItemType Directory -Path $HostDir -Force | Out-Null }
Invoke-Action "copy $HostExePath to $HostExe" { Copy-Item -Path $HostExePath -Destination $HostExe -Force }
Invoke-Action "write $ManifestPath allowing only chrome-extension://$ExtensionId/" {
    # No byte-order mark: Windows PowerShell's -Encoding UTF8 adds one, which browsers may reject.
    [System.IO.File]::WriteAllText($ManifestPath, (New-HostManifest), (New-Object System.Text.UTF8Encoding($false)))
}

if (-not $SkipRegistry) {
    foreach ($browser in $Browsers) {
        Write-Step "Configuring $($browser.Name)"
        Invoke-Action "register the host: $($browser.HostKey) = $ManifestPath" {
            Set-RegistryValue -Path $browser.HostKey -Name "(default)" -Value $ManifestPath
        }

        if (-not $SkipForceInstall) {
            $entry = "$ExtensionId;$($browser.UpdateUrl)"
            Invoke-Action "force-install the extension: $entry" {
                Set-ForceInstall -PolicyKey $browser.PolicyKey -Entry $entry
            }
        }

        if (-not $SkipHardening) {
            Invoke-Action "disable private browsing ($($browser.PrivateModeName) = 1)" {
                Set-RegistryValue -Path $browser.PolicyKey -Name $browser.PrivateModeName -Value 1 -Type DWord
            }
            Invoke-Action "disable Guest mode (BrowserGuestModeEnabled = 0)" {
                Set-RegistryValue -Path $browser.PolicyKey -Name "BrowserGuestModeEnabled" -Value 0 -Type DWord
            }
        }
    }
}

Write-Host ""
Write-Host "Done. Restart the browsers, then open chrome://policy or edge://policy and confirm the extension is force-installed." -ForegroundColor Green
Write-Host "The Student Agent must be running for the extension to connect; see provisioning/browser-integration.md for the checklist." -ForegroundColor Green
