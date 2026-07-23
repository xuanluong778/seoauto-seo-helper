# Staging environment setup — SEOAuto SEO Helper Live E2E

## Goal

Isolated staging for Private Plugin Updater: `v1.0.4` → `v1.1.0-beta.1` without touching production.

## 1) Plugin GitHub remote

Repo currently has **no remote**. Suggested remote (create empty repo first):

```bash
cd "d:/App/SEOAuto SEO Helper"
gh auth login
gh repo create xuanluong778/seoauto-seo-helper --private --source=. --remote=origin --push
git push -u origin feature/seo-helper-private-updater
# Do NOT push stable tags. Only beta:
# (after version bump to 1.1.0-beta.1)
git tag -a v1.1.0-beta.1 -m "beta E2E"
git push origin v1.1.0-beta.1
```

## 2) SaaS staging on VPS

Host: `webauto-vps` (`116.118.45.72`)  
Scripts: `scripts/staging/bootstrap-saas-staging.sh`

- Root: `/var/www/seoauto_vn_usr/data/www-staging`
- Service: `digiseo-staging.service` (port `8901`) — **not** `digiseo.service`
- DB: `seoauto_staging` / Redis DB `5`
- Domain: `staging.seoauto.vn` (needs DNS A → `116.118.45.72`)
- Commit: `f2b1f1f`

## 3) Object storage

Prefer Cloudflare R2 bucket `seoauto-plugin-staging`.  
Fallback: MinIO via `bootstrap-minio-staging.sh` (S3-compatible; CI needs public endpoint).

## 4) WordPress staging

Script: `bootstrap-wp-staging.sh` → `seohelper-staging.siteauto.vn`  
Install Helper **v1.0.4**, Rank Math, Wordfence; backup under `/var/backups/seohelper-staging/`.

## 5) GitHub Secrets (staging only)

| Secret | Value |
|--------|--------|
| `SEOAUTO_API_BASE` | `https://staging.seoauto.vn` |
| `WP_PLUGIN_CI_RELEASE_TOKEN` | from `…/www-staging/data/staging_ci_secrets.env` |
| `WP_PLUGIN_RELEASE_SIGNING_KEY` | same file |
| `R2_*` | staging bucket only |

## 6) DNS prerequisites (manual if no API)

1. `staging.seoauto.vn` A → `116.118.45.72`
2. `seohelper-staging.siteauto.vn` A → `116.118.45.72`
3. `certbot --nginx -d staging.seoauto.vn -d seohelper-staging.siteauto.vn`
