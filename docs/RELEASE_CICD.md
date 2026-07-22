# Release CI/CD — SEOAuto SEO Helper

## GitHub Secrets

| Secret | Purpose |
|--------|---------|
| `WP_PLUGIN_RELEASE_SIGNING_KEY` | HMAC key for package signature in manifest |
| `WP_PLUGIN_CI_RELEASE_TOKEN` | Auth for `/api/wordpress-plugin/releases*` |
| `SEOAUTO_API_BASE` | e.g. `https://seoauto.vn` or staging URL |
| `R2_BUCKET` | Cloudflare R2 bucket |
| `R2_ENDPOINT_URL` | R2 S3 API endpoint |
| `R2_ACCESS_KEY_ID` | R2 access key |
| `R2_SECRET_ACCESS_KEY` | R2 secret |
| `R2_PREFIX` | Optional object prefix (default `plugin-releases`) |

Optional repo variable: `ROLLOUT_PERCENT` (canary 0–100).

## SaaS env

| Env | Purpose |
|-----|---------|
| `WP_PLUGIN_STORAGE_BACKEND` | `r2` / `s3` / `local` |
| `R2_BUCKET`, `R2_ENDPOINT_URL`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_PREFIX` | Object storage |
| `WP_PLUGIN_CI_RELEASE_TOKEN` | Must match GitHub secret |
| `WP_PLUGIN_RELEASE_SIGNING_KEY` | Must match GitHub secret |
| `WP_PLUGIN_DOWNLOAD_TTL_SECONDS` | Default 900 |
| `WP_PLUGIN_MIN_KEEP_RELEASES` | Default 3 |
| `PUBLIC_BASE_URL` | Used when minting download URLs |

## Tag flow

```bash
# 1. Bump version (header + constant + readme Stable tag) — no -dev on stable
git commit -am "release: v1.1.0"
git tag -a v1.1.0 -m "SEOAuto SEO Helper v1.1.0"
git push origin v1.1.0

# 2. Actions creates draft only
# 3. Staging E2E
# 4. Actions → Publish SEO Helper release (workflow_dispatch, staging_e2e_passed=true)
```

Beta tags containing `-beta` use channel `beta`.

## Withdraw

```bash
export SEOAUTO_API_BASE=https://seoauto.vn
export WP_PLUGIN_CI_RELEASE_TOKEN=...
bash scripts/ci/withdraw-release.sh 1.1.0 stable "checksum incident"
```
