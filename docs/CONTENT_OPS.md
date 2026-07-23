<?php
/**
 * Phase 2 ContentOps API docs (staging).
 *
 * @package SEOAuto\SEOHelper
 */

# ContentOps API (Phase 2)

Namespace: `seoauto/v1`  
Auth: HMAC headers (Site-ID, Connection-ID, Timestamp, Nonce, Request-ID, Signature)  
Feature: `content_ops` (explicit; not implied by `seo_helper` in production)

## Flow

`Preview → Backup → Apply → Recheck → Rollback`

| Step | Method | Path | Mutates? |
|------|--------|------|----------|
| Preview | POST | `/content/preview` | **No** |
| Backup | POST | `/content/backup` | Yes (snapshot only) |
| Apply | POST | `/content/apply` | Yes (blocked if backup failed) |
| Recheck | POST | `/content/recheck` | No (verify only) |
| Rollback | POST | `/content/rollback` | Yes (restore snapshot) |
| Get batch | GET | `/content/batches/{id}` | No |
| List | GET | `/content/batches` | No |

## Backup payload fields

title, content, excerpt, slug, status, taxonomies, featured_image_id, custom_fields (secrets stripped), SEO meta (Rank Math / Yoast / AIOSEO / native).

Retention: **30 days** (cron `seoauto_helper_content_ops_purge`).

## Security

- Connection ownership on every batch (IDOR blocked)
- Idempotency via `request_id`
- Per-post locks during Apply/Rollback
- Rollback conflict when post checksum changed after Apply (`force=true` override)
- Audit log redacts tokens / signed URLs; does not store full content in log context
