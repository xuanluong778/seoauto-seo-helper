# Set GitHub secrets from VPS without printing values.
$ErrorActionPreference = 'Stop'
Set-Location 'd:\App\SEOAuto SEO Helper'
if (-not $env:GH_TOKEN) {
  $fill = "protocol=https`nhost=github.com`n`n" | git credential fill 2>$null
  $env:GH_TOKEN = ($fill | Select-String -Pattern '^password=(.+)$').Matches.Groups[1].Value
}
if (-not $env:GH_TOKEN) { throw 'GH_TOKEN missing' }
$env:GH_PROMPT_DISABLED = '1'
$Repo = 'xuanluong778/seoauto-seo-helper'
$keys = @(
  'SEOAUTO_API_BASE',
  'WP_PLUGIN_CI_RELEASE_TOKEN',
  'WP_PLUGIN_RELEASE_SIGNING_KEY',
  'R2_BUCKET',
  'R2_ENDPOINT_URL',
  'R2_ACCESS_KEY_ID',
  'R2_SECRET_ACCESS_KEY',
  'R2_PREFIX',
  'R2_FORCE_PATH_STYLE'
)
foreach ($k in $keys) {
  $proc = Start-Process -FilePath 'ssh' -ArgumentList @(
    '-o','BatchMode=yes','webauto-vps',"bash /tmp/seohelper-staging-scripts/emit-secret.sh $k"
  ) -RedirectStandardOutput "$env:TEMP\gh-secret-pipe.txt" -NoNewWindow -Wait -PassThru
  if ($proc.ExitCode -ne 0) { throw "emit failed for $k" }
  $len = (Get-Item "$env:TEMP\gh-secret-pipe.txt").Length
  if ($len -lt 1) { throw "empty value for $k" }
  Get-Content -Raw "$env:TEMP\gh-secret-pipe.txt" | gh secret set $k -R $Repo
  Remove-Item -Force "$env:TEMP\gh-secret-pipe.txt" -ErrorAction SilentlyContinue
  Write-Host "SET $k ok (bytes=$len)"
}
Write-Host '--- secret names ---'
gh secret list -R $Repo
