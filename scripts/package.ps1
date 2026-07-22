#Requires -Version 5.1
<#
.SYNOPSIS
  Build seoauto-seo-helper.zip for WordPress upload.

  WordPress requires exactly:
    seoauto-seo-helper/seoauto-seo-helper.php
  NOT nested like seoauto-seo-helper/seoauto-seo-helper/seoauto-seo-helper.php
#>
$ErrorActionPreference = 'Stop'
$Root    = Split-Path -Parent $PSScriptRoot
$OutDir  = Join-Path $Root 'dist'
$Slug    = 'seoauto-seo-helper'
$Stage   = Join-Path $OutDir $Slug
$ZipPath = Join-Path $Root 'seoauto-seo-helper.zip'
$MainEntry = "$Slug/seoauto-seo-helper.php"
$BadNested = "$Slug/$Slug/seoauto-seo-helper.php"

& (Join-Path $PSScriptRoot 'run-qa.ps1')

if (Test-Path $Stage) { Remove-Item $Stage -Recurse -Force }
if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }
New-Item -ItemType Directory -Path $Stage -Force | Out-Null

$include = @(
    'seoauto-seo-helper.php',
    'uninstall.php',
    'readme.txt',
    'README.md',
    'includes',
    'assets',
    'docs'
)
foreach ($item in $include) {
    $src = Join-Path $Root $item
    if (Test-Path $src) {
        Copy-Item -Path $src -Destination (Join-Path $Stage $item) -Recurse -Force
    }
}

# Strip development / secret artifacts if present under includes/docs.
$denyNames = @('.env', '.env.local', '.git', '.gitignore', 'phpunit.xml', 'composer.json', 'composer.lock')
Get-ChildItem -Path $Stage -Recurse -Force | Where-Object {
    $denyNames -contains $_.Name -or $_.FullName -match '\\(\.git|tests|vendor|node_modules|logs|cache)\\'
} | ForEach-Object {
    Remove-Item $_.FullName -Recurse -Force -ErrorAction SilentlyContinue
}

if (-not (Test-Path (Join-Path $Stage 'readme.txt'))) {
    throw 'readme.txt missing from package'
}
if (-not (Test-Path (Join-Path $Stage 'seoauto-seo-helper.php'))) {
    throw 'seoauto-seo-helper.php missing from stage'
}

# Build ZIP with explicit entry paths (avoids double-folder nesting on some hosts).
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zip = [System.IO.Compression.ZipFile]::Open($ZipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -Path $Stage -Recurse -File | ForEach-Object {
        $relative  = $_.FullName.Substring($Stage.Length).TrimStart('\', '/')
        $entryName = ($Slug + '/' + ($relative -replace '\\', '/'))
        [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $entryName)
    }
} finally {
    $zip.Dispose()
}

# Validate structure before delivery.
$verify = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
try {
    $hasMain = $false
    $hasBad  = $false
    foreach ($entry in $verify.Entries) {
        if ($entry.FullName -eq $MainEntry) { $hasMain = $true }
        if ($entry.FullName -eq $BadNested) { $hasBad = $true }
        if ($entry.FullName -match "^$Slug/$Slug/") { $hasBad = $true }
    }
    if (-not $hasMain) {
        throw "ZIP invalid: missing entry $MainEntry"
    }
    if ($hasBad) {
        throw "ZIP invalid: double-nested folder detected ($BadNested)"
    }
} finally {
    $verify.Dispose()
}

$size = (Get-Item $ZipPath).Length
$sha  = (Get-FileHash -Path $ZipPath -Algorithm SHA256).Hash.ToLowerInvariant()
Write-Host "Created: $ZipPath ($([math]::Round($size/1KB, 1)) KB)" -ForegroundColor Green
Write-Host "Verified: $MainEntry" -ForegroundColor Green
Write-Host "SHA256: $sha" -ForegroundColor Cyan
Get-ChildItem $ZipPath | Format-List Name, Length, LastWriteTime

# Sidecar for release registry upload
$shaFile = Join-Path $Root 'seoauto-seo-helper.zip.sha256'
Set-Content -Path $shaFile -Value $sha -NoNewline
Write-Host "Wrote: $shaFile" -ForegroundColor Cyan
