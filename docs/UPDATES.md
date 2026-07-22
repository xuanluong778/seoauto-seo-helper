# Private Plugin Updates — SEOAuto SEO Helper

## Plugin

- Header: `Update URI: https://seoauto.vn/plugin/seoauto-seo-helper`
- Filter: `update_plugins_seoauto.vn`
- Modules: `includes/Updater/*`
- Cache: 6 hours (`seoauto_helper_update_check_cache`)
- Admin: **Kiểm tra cập nhật** on Overview; Plugins row uses core “Update now”

## SaaS API

| Method | Path | Auth |
|--------|------|------|
| POST | `/api/wordpress-plugin/updates/check` | Plugin HMAC (`site_id` + `connection_id` + signature) |
| GET | `/api/wordpress-plugin/updates/download/{token}` | One-time short-lived token |

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

`release_signature` = `HMAC-SHA256(site_secret, version|sha256|expires_at)`.

## Release workflow

1. `scripts/package.ps1` → `seoauto-seo-helper.zip` + `.sha256`
2. Copy ZIP to `data/plugin_releases/seoauto-seo-helper/`
3. Insert / publish row in `wordpress_plugin_releases` (`status=published`, channel `stable|beta`)
4. Sites poll via WP updates; LOCKED sites still receive security patches

## Tables

- `wordpress_plugin_releases`
- `wordpress_plugin_download_tokens`
- `wordpress_plugin_update_events`

## Entitlement

Update path is **independent** of `seo_audit` / paid features. LOCKED does not block security updates; paid feature gates remain elsewhere.
