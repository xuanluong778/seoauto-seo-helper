# Private Plugin Updates — SEOAuto SEO Helper

## Plugin

- Header: `Update URI: https://seoauto.vn/plugin/seoauto-seo-helper`
- Filter: `update_plugins_seoauto.vn`
- Modules: `includes/Updater/*`
- Cache: 6 hours (`seoauto_helper_update_check_cache`)
- Admin: **Kiểm tra cập nhật** on Overview; Plugins row uses core “Update now”

## Release CI/CD

1. Bump version in `seoauto-seo-helper.php` (header + `SEOAUTO_HELPER_VERSION`) and `readme.txt` `Stable tag`
2. Commit on release branch → tag `vX.Y.Z` (no `-dev` on stable) → push tag
3. GitHub Actions [`.github/workflows/release-plugin.yml`](../.github/workflows/release-plugin.yml):
   - verify versions → QA → build ZIP → SHA-256 + signature → upload R2 → **create draft** release
4. Staging E2E (`scripts/e2e-updater-staging.php` + live WP)
5. Publish via [`.github/workflows/publish-plugin-release.yml`](../.github/workflows/publish-plugin-release.yml) with `staging_e2e_passed=true`

Withdraw: `scripts/ci/withdraw-release.sh <version> [channel] [reason]`

## SaaS API

| Method | Path | Auth |
|--------|------|------|
| POST | `/api/wordpress-plugin/updates/check` | Plugin HMAC (`site_id` + `connection_id` + signature) |
| GET | `/api/wordpress-plugin/updates/download/{token}` | One-time short-lived token |
| POST | `/api/wordpress-plugin/releases` | CI token (`X-SEOAuto-CI-Token`) → **draft** |
| GET | `/api/wordpress-plugin/releases` | CI token |
| GET | `/api/wordpress-plugin/releases/{version}` | CI token |
| POST | `/api/wordpress-plugin/releases/{version}/publish` | CI token |
| POST | `/api/wordpress-plugin/releases/{version}/withdraw` | CI token |

### Check response

```json
{
  "update_available": true,
  "version": "1.1.0",
  "package": "https://seoauto.vn/api/wordpress-plugin/updates/download/...",
  "requires": "6.0",
  "requires_php": "8.1",
  "tested": "6.7",
  "changelog_url": "https://seoauto.vn/...",
  "sha256": "...",
  "release_signature": "...",
  "channel": "stable",
  "autoupdate": false,
  "download_expires_at": "ISO-8601"
}
```

`release_signature` (per site download) = `HMAC-SHA256(site_secret, version|sha256|expires_at)`.  
Package registry signature (CI) = `HMAC-SHA256(WP_PLUGIN_RELEASE_SIGNING_KEY, version|sha256|channel)`.

Signed download URLs are minted only on update check; TTL short; never stored/logged in full.

## Storage

- Adapter: `StorageAdapter` (default Cloudflare R2/S3, local fallback)
- DB stores `storage_key`, `sha256`, metadata — **not** ZIP bytes

## Entitlement

Update path is **independent** of `seo_audit` / paid features. LOCKED does not block security updates; paid feature gates remain elsewhere.

## Rollback / withdraw

- Keep ≥ 3 published versions; withdraw bad release → sites stop receiving it
- Sites already on bad version: re-publish prior version only if newer than installed (anti-downgrade); otherwise manual WP restore / Phase 2 Backup (not in this task)
