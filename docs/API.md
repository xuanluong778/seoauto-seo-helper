# REST API — SEOAuto SEO Helper

Base: `https://{site}/wp-json/seoauto/v1`

**Auth:** HMAC-SHA256 only. Không Basic Auth / Application Password.

## Headers bắt buộc

| Header | Mô tả |
|--------|--------|
| `X-SEOAuto-Site-ID` | `site_id` từ pairing |
| `X-SEOAuto-Connection-ID` | `connection_id` |
| `X-SEOAuto-Timestamp` | Unix seconds, ±300s |
| `X-SEOAuto-Nonce` | One-time |
| `X-SEOAuto-Request-ID` | Idempotency key (ổn định qua retry) |
| `X-SEOAuto-Signature` | HMAC canonical (xem README) |
| `X-SEOAuto-Organization-ID` | Optional; nếu gửi phải khớp org đã pair |

Canonical:

```
METHOD\nPATH\nTIMESTAMP\nNONCE\nREQUEST_ID\nSHA256_HEX(body)
```

## Endpoints

| Method | Path | Entitlement | Mô tả |
|--------|------|-------------|--------|
| GET | `/status` | Không | Snapshot kết nối, lock, features |
| POST | `/connect` | Không | Push entitlement đã ký |
| POST | `/disconnect` | Không | Ngắt kết nối phía plugin |
| POST | `/entitlement/refresh` | Không | Cập nhật entitlement + lock state |
| POST | `/health-check` | Không | Self-check HMAC + secret |
| GET | `/logs` | Không | Audit log (redacted) |
| POST | `/audit/scan` | Có (`seo_audit`) | Enqueue SEO audit scan (WP-Cron batch) |
| GET | `/audit/runs/{id}` | Có (`seo_audit`) | Trạng thái audit run |
| GET | `/audit/issues` | Có (`seo_audit`) | Danh sách findings |
| GET | `/jobs/{id}` | Không (HMAC) | Poll background job |

## POST /audit/scan

```json
{
  "request_id": "uuid-stable",
  "post_types": ["post", "page", "product"],
  "batch_size": 20,
  "mode": "scan_only"
}
```

Response **202**: `job_id`, `run_id`, `checkers[]`. Replay cùng `request_id` → `idempotent_replay: true`.

LOCKED → `403 seoauto_plugin_locked` (không xóa kết quả cũ).

| Method | Path | Entitlement | Mô tả |
|--------|------|-------------|--------|
| POST | `/posts` | Có (`seo_helper`) | Tạo bài — `request_id`, `source_article_id` bắt buộc |
| PATCH | `/posts/{id}` | Có | Cập nhật bài đã map |
| POST | `/posts/{id}/schedule` | Có | Lên lịch `scheduled_at` |
| POST | `/media` | Có | Upload ảnh (URL / base64 / multipart) |
| POST | `/seo-meta` | Có (`yoast_sync`) | Đồng bộ SEO meta qua adapter |

## POST /posts — payload chính

```json
{
  "request_id": "uuid-stable",
  "source_article_id": "project-abc",
  "title": "...",
  "content": "<p>...</p>",
  "status": "draft|publish|future|pending|private",
  "slug": "...",
  "excerpt": "...",
  "scheduled_at": "2026-07-22T10:00:00+07:00",
  "categories": [1, 2],
  "tags": ["a", "b"],
  "featured_image": "https://...",
  "connection_id": 42
}
```

**Response 201:** `post_id`, `permalink`, `edit_url`, `status`  
**Replay:** `idempotent_replay: true` + response cached

## Mã lỗi (`seoauto_*`)

| Code | HTTP | Ý nghĩa |
|------|------|---------|
| `seoauto_bad_signature` | 401 | HMAC sai |
| `seoauto_nonce_replay` | 401 | Nonce trùng |
| `seoauto_timestamp_expired` | 401 | Timestamp > 5 phút |
| `seoauto_plugin_locked` | 403 | Gói hết hạn / LOCKED |
| `seoauto_site_mismatch` | 403 | Sai site_id |
| `seoauto_connection_mismatch` | 403 | Sai connection_id |
| `seoauto_organization_mismatch` | 403 | Sai organization |
| `seoauto_body_too_large` | 413 | Body > 2 MiB |
| `seoauto_rate_limited` | 429 | > 60 req/phút/site |
| `seoauto_article_exists` | 409 | Trùng `source_article_id` |
| `seoauto_request_id_mismatch` | 400 | Body/header request_id lệch |
| `wordfence_blocked` | — | (SaaS) WAF 403 trước plugin |

## Rate limit

60 requests / 60 giây / `site_id` (filter: `seoauto_helper_rate_limit`).

## Body size

Tối đa 2 MiB JSON (filter: `seoauto_helper_max_request_body_bytes`).

## SaaS backend (tham chiếu)

| Method | Path |
|--------|------|
| POST | `/api/wordpress-plugin/pairing-codes` |
| POST | `/api/wordpress-plugin/pair` |
| POST | `/api/wordpress-plugin/jobs` |
| GET | `/api/wordpress-plugin/jobs/{id}` |
