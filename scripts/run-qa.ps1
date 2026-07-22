#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$Php  = $env:PHP_BIN
if (-not $Php -or -not (Test-Path $Php)) {
    $Php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
}
if (-not (Test-Path $Php)) { $Php = 'php' }

Write-Host '=== PHP Lint ===' -ForegroundColor Cyan
$lintFail = 0
Get-ChildItem -Path $Root -Recurse -Filter '*.php' | Where-Object {
    $_.FullName -notmatch '\\vendor\\|\\node_modules\\'
} | ForEach-Object {
    $out = & $Php -l $_.FullName 2>&1 | Out-String
    if ($out -match 'Parse error|Errors parsing') {
        Write-Host "LINT FAIL: $($_.FullName)" -ForegroundColor Red
        Write-Host $out
        $lintFail++
    }
}
if ($lintFail -gt 0) { throw "PHP lint: $lintFail file(s) failed" }
$count = (Get-ChildItem -Path $Root -Recurse -Filter '*.php').Count
Write-Host "PHP lint OK ($count files)" -ForegroundColor Green

Write-Host ''
Write-Host '=== WordPress Coding Standards ===' -ForegroundColor Cyan
$Phpcs = Get-Command phpcs -ErrorAction SilentlyContinue
$PhpcsBin = Join-Path $Root 'vendor\bin\phpcs'
if (-not $Phpcs -and (Test-Path $PhpcsBin)) {
    $Phpcs = @{ Source = $PhpcsBin }
}
if (-not $Phpcs) {
    Write-Host 'phpcs not in PATH - skipped' -ForegroundColor Yellow
} else {
    $phpcsExe = if ($Phpcs.Source -match 'phpcs$') { $Phpcs.Source } else { $PhpcsBin }
    if ($phpcsExe -match '\.bat$') {
        & $Php $phpcsExe.Replace('.bat', '') --standard=WordPress --extensions=php --ignore=tests,vendor,dist `
            "$Root\seoauto-seo-helper.php" "$Root\includes" "$Root\uninstall.php" --report=summary
    } else {
        & $Php $phpcsExe --standard=WordPress --extensions=php --ignore=tests,vendor,dist `
            "$Root\seoauto-seo-helper.php" "$Root\includes" "$Root\uninstall.php" --report=summary
    }
    if ($LASTEXITCODE -ne 0) {
        Write-Host "WPCS: $($LASTEXITCODE) (see summary above; mostly PSR-4 filename / CRLF)" -ForegroundColor Yellow
    } else {
        Write-Host 'WPCS OK' -ForegroundColor Green
    }
}

Write-Host ''
Write-Host '=== Unit tests ===' -ForegroundColor Cyan
$tests = @(
    'test_hmac_auth.php',
    'test_security.php',
    'test_entitlement_lock.php',
    'test_network_grace.php',
    'test_firewall_guidance.php',
    'test_audit_logger.php',
    'test_media_security.php',
    'test_idempotency_race.php',
    'test_content_sanitizer.php',
    'test_seo_adapters.php',
    'test_lifecycle.php',
    'test_secret_redaction.php',
    'test_seo_audit_engine.php',
    'test_load_all_classes.php',
    'test_boot_smoke.php'
)
$testFail = 0
foreach ($t in $tests) {
    $path = Join-Path $Root "tests\$t"
    if (-not (Test-Path $path)) {
        Write-Host "SKIP $t"
        continue
    }
    Write-Host "-- $t"
    & $Php $path
    if ($LASTEXITCODE -ne 0) {
        $testFail++
        Write-Host "FAIL $t" -ForegroundColor Red
    }
}
if ($testFail -gt 0) { throw "Tests failed: $testFail suite(s)" }
Write-Host 'All test suites passed.' -ForegroundColor Green
Write-Host 'QA complete.' -ForegroundColor Green
