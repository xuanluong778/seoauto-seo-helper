# ContentOps API (Phase 2)

Namespace: `seoauto/v1`  
Auth: HMAC headers (Site-ID, Connection-ID, Timestamp, Nonce, Request-ID, Signature)  
Feature: `content_ops` — **SaaS explicit only** (in signed `enabled_features`). Not implied by `seo_helper`. No `WP_DEBUG` fallback.

Plugin refreshes entitlement via `GET /api/wordpress-plugin/entitlement` with plugin HMAC; SaaS recomputes features from plan catalog (includes `content_ops` in `SEO_HELPER_CAPABILITIES`).

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
