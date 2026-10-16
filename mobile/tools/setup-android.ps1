<#
 One-time setup for the Flutter mobile app on Windows.
 Run from the `mobile` folder:   powershell -ExecutionPolicy Bypass -File tools\setup-android.ps1

 1. Generates the Android project (flutter create) if it does not exist yet.
 2. Adds the permissions the app needs to AndroidManifest.xml (idempotent - safe to run again).
 3. Allows plain-HTTP to your own PC while developing (debug builds only).
 4. Runs `flutter pub get`.
#>
$ErrorActionPreference = 'Stop'
if (-not (Get-Command flutter -ErrorAction SilentlyContinue)) {
  throw "Flutter is not on PATH. Install the Flutter SDK first (see mobile/README.md)."
}
if (-not (Test-Path 'pubspec.yaml')) { throw "Run this from the 'mobile' folder (pubspec.yaml not found)." }

if (-not (Test-Path 'android')) {
  Write-Host '== Generating the Android project ==' -ForegroundColor Cyan
  flutter create . --platforms=android --org com.metaintel --project-name field_ops_mobile
}

$manifest = 'android\app\src\main\AndroidManifest.xml'
$xml = Get-Content $manifest -Raw

function Add-Once([string]$needle, [string]$insertAfterPattern, [string]$text) {
  if ($script:xml -notmatch [regex]::Escape($needle)) {
    $script:xml = [regex]::Replace($script:xml, $insertAfterPattern, { param($m) $m.Value + "`r`n    " + $text }, 1)
    Write-Host "  added: $needle"
  }
}

Write-Host '== Updating AndroidManifest.xml ==' -ForegroundColor Cyan
$anchor = '<manifest[^>]*>'
Add-Once 'android.permission.INTERNET'              $anchor '<uses-permission android:name="android.permission.INTERNET"/>'
Add-Once 'android.permission.ACCESS_NETWORK_STATE'  $anchor '<uses-permission android:name="android.permission.ACCESS_NETWORK_STATE"/>'
Add-Once 'android.permission.ACCESS_FINE_LOCATION'  $anchor '<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION"/>'
Add-Once 'android.permission.ACCESS_COARSE_LOCATION' $anchor '<uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION"/>'
Add-Once 'android.permission.CAMERA'                $anchor '<uses-permission android:name="android.permission.CAMERA"/>'

# Android 11+: needed so url_launcher can open Google Maps.
if ($xml -notmatch '<queries>') {
  $xml = $xml -replace '</manifest>', "    <queries>`r`n        <intent><action android:name=`"android.intent.action.VIEW`"/><data android:scheme=`"https`"/></intent>`r`n    </queries>`r`n</manifest>"
  Write-Host '  added: <queries> for https links'
}

# Development convenience: the API on your PC is plain HTTP. Production must use HTTPS - remove this line then.
if ($xml -notmatch 'usesCleartextTraffic') {
  $xml = $xml -replace '<application', '<application android:usesCleartextTraffic="true"'
  Write-Host '  added: usesCleartextTraffic (development only - remove for production)'
}
Set-Content -Path $manifest -Value $xml -Encoding UTF8

# Gradle: the plugins (geolocator, secure storage) need minSdk 23+.
$gradle = Get-ChildItem 'android\app' -Filter 'build.gradle*' | Select-Object -First 1
if ($gradle) {
  $g = Get-Content $gradle.FullName -Raw
  if ($g -match 'minSdk(Version)?\s*=?\s*flutter\.minSdkVersion') {
    $g = $g -replace 'minSdk(Version)?\s*=?\s*flutter\.minSdkVersion', 'minSdk = 23'
    Set-Content -Path $gradle.FullName -Value $g -Encoding UTF8
    Write-Host '  set minSdk = 23'
  }
}

Write-Host '== flutter pub get ==' -ForegroundColor Cyan
flutter pub get
Write-Host "`nDone. Next: start the web stack (docker compose up -d), then:  flutter run" -ForegroundColor Green
