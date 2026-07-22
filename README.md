# SEOAuto SEO Helper

WordPress plugin (PHP 8.1+, WP 6.x) — namespace `SEOAuto\SEOHelper`, prefix `seoauto_helper_`.

## Structure

```
seoauto-seo-helper.php          Bootstrap
includes/Plugin.php             Orchestrator
includes/Activator.php
includes/Deactivator.php
includes/Admin/Admin_Menu.php
includes/Admin/Overview_Page.php
includes/Admin/Connect_Page.php
includes/Admin/Logs_Page.php
includes/Connection/Connection_Manager.php
includes/Entitlement/Entitlement_Manager.php
includes/Rest/Rest_Controller.php
includes/Auth/Request_Authenticator.php
includes/Post/Post_Service.php
includes/Media/Media_Service.php
includes/Seo/                   OG, Schema, Yoast, Rank Math
includes/Audit/Audit_Logger.php
includes/Cron/Cron_Scheduler.php
```

## Admin

**SEOAuto Helper** — 3 trang trong wp-admin:

| Trang | Nội dung |
|-------|----------|
| **Tổng quan** | Trạng thái kết nối, gói/hết hạn, features, plugin SEO, Wordfence, lần kết nối gần nhất, số bài đã đăng, lỗi gần nhất |
| **Kết nối** | Ghép nối SA-XXXX, kiểm tra gói/kết nối, post type được phép |
| **Nhật ký** | `request_id`, action, post_id, status, error_code — tự xóa sau 30 hoặc 90 ngày |

Ghép nối:

1. Nhập API base HTTPS + mã `SA-XXXX-XXXX` từ SEOAuto
2. Plugin gửi website info qua HTTPS → nhận `connection_id`, `site_id`, `site_secret`, entitlement
3. `site_secret` mã hóa AES-256-GCM (WP salts), option `autoload=no`
4. Không dùng mật khẩu Admin, Application Password, cookie, hay 2FA

Nhật ký **không** hiển thị secret, token, signature, mật khẩu hoặc nội dung bài đầy đủ.

## REST `/wp-json/seoauto/v1`

Không có route public. Mọi endpoint dùng `permission_callback` thật (HMAC + rate limit; entitlement theo route).

| Method | Route | Entitlement |
|--------|-------|-------------|
| GET | `/status` | không bắt buộc |
| POST | `/connect` | không bắt buộc |
| POST | `/disconnect` | không bắt buộc |
| POST | `/entitlement/refresh` | không bắt buộc |
| POST | `/posts` | bắt buộc + feature `seo_helper` |
| PATCH | `/posts/{id}` | bắt buộc + feature `seo_helper` |
| POST | `/posts/{id}/schedule` | bắt buộc + feature `seo_helper` |
| POST | `/media` | bắt buộc + feature `seo_helper` |
| POST | `/seo-meta` | bắt buộc + feature `yoast_sync` |
| POST | `/health-check` | không bắt buộc |
| GET | `/logs` | không bắt buộc |

Mỗi request: HTTPS, HMAC headers, nonce one-time, ±5 phút, rate limit, validate body, mã lỗi chuẩn (`seoauto_*` + HTTP status).

## Đăng bài (SEOAuto → WP)

Payload `POST /posts` / `PATCH /posts/{id}` hỗ trợ:

- `status`: `draft` | `publish` | `future` | `pending` | `private`
- `scheduled_at` (ISO8601) — tạo lịch hoặc `POST /posts/{id}/schedule`
- `slug`, `excerpt`, `author` / `author_id`
- `categories` (id hoặc tên), `tags` (id hoặc tên; tag mới được tạo)
- `featured_image` (URL) hoặc `featured_image_id` / `featured_media`
- `source_article_id` — lưu `_seoauto_source_article_id`
- `connection_id` — lưu `_seoauto_connection_id` (khớp connection đã pair)
- `post_type` — chỉ các type admin đã bật (mặc định `post`)

Response: `post_id`, `permalink`, `edit_url`, `status` (+ `scheduled_at` nếu có).

Bảo mật nội dung: strip PHP tags, strip shortcode nguy hiểm, `wp_kses_post`. Từ chối `meta` / `post_meta` / `meta_input` tùy ý.

### Idempotency

Mỗi lệnh đăng bài bắt buộc có `request_id` + `source_article_id` (request_id có thể lấy từ header `X-SEOAuto-Request-ID`).

- Bảng `wp_seoauto_helper_idempotency` — UNIQUE(`request_id`): Celery retry trả response cũ (`idempotent_replay`).
- Bảng `wp_seoauto_helper_article_map` — UNIQUE(`connection_id`,`source_article_id`) → `post_id`.
- Create khi article đã map → `409 seoauto_article_exists` (trừ `force_create` + filter `seoauto_helper_allow_force_create`).
- Update/schedule dùng đúng post đã map; khóa `GET_LOCK` theo article chống race.

Tests: `php tests/test_idempotency_race.php`

## Media upload

`POST /wp-json/seoauto/v1/media` (HMAC):

- Nguồn: multipart `file`, JSON `url` (HTTPS), hoặc `file_base64` + `filename`
- Meta: `alt`, `title`, `caption`, `description`
- `post_id` + `set_featured` để gắn featured image
- Chống trùng: `source_image_id` + SHA-256 `file_hash` (bảng `seoauto_helper_media_map`)

Bảo mật: MIME thực (`finfo` + `getimagesize`), max 5MB (filter), chặn PHP/PHAR/exe, SVG mặc định off, SSRF (localhost/private/link-local/metadata), redirect ≤3 + timeout 15s.

Response: `attachment_id`, `url`, `width`, `height`, `mime`, `file_hash`, `source_image_id`, `deduplicated`.

Tests: `php tests/test_media_security.php`

## SEO adapters

Một adapter duy nhất theo thứ tự: **Rank Math → Yoast → AIOSEO → Native**.

Đồng bộ: focus keyword, SEO title, meta description, canonical, robots, schema type, social title/description/image.

- Khi Rank Math / Yoast / AIOSEO active: chỉ ghi meta/API của plugin đó — **không** xuất canonical/robots/OG/schema/sitemap thứ hai.
- Native fallback: title, meta description, canonical, `wp_robots`, Open Graph, JSON-LD — chỉ khi không có plugin SEO.

Tests: `php tests/test_seo_adapters.php`

Admin: **SEOAuto Helper → Kết nối** → checkbox post type được phép.

## Entitlement & LOCKED

Kiểm tra quyền khi:

- Mở trang admin plugin (`refresh_check` nguồn `admin_page`)
- Trước mỗi lệnh REST thay đổi dữ liệu (`require_entitlement` → `403 seoauto_plugin_locked`)
- WP-Cron mỗi 6 giờ (`seoauto_six_hours` → `cron`)
- Nút **Kiểm tra lại gói** (`admin_button`)
- SaaS push `POST /entitlement/refresh` (cập nhật cache + `apply_lock_state`)

Khi gói **expired**, **canceled**, **suspended**, **downgraded** hoặc **không hỗ trợ**:

- Trạng thái chuyển sang `locked` (badge **LOCKED** trên admin)
- Chặn: đăng bài, cập nhật, upload ảnh, SEO meta, audit tự động cho thao tác bị chặn, cron đồng bộ mutation
- Vẫn cho: xem log admin, kiểm tra gói/kết nối, link nâng cấp, ngắt kết nối, `GET /status`, `GET /logs`, `POST /disconnect`, `POST /entitlement/refresh`

**Không** tự deactivate plugin, **không** xóa bài/ảnh/meta. Khi user nâng cấp lại, SaaS gửi entitlement hợp lệ → plugin tự mở khóa (`connected`) mà không cần cài lại.

### Grace period mạng (tối đa 48 giờ)

Khi pull `GET /api/wordpress-plugin/entitlement` gặp lỗi **timeout, DNS, 502, 503, 504**:

- Chỉ áp dụng nếu entitlement cache trước đó **allowed + active**, và `now <= network_grace_until`
- `network_grace_until` chỉ cập nhật khi SaaS trả entitlement thành công (từ `network_grace_until` hoặc `grace_until`, cap 48h) — **plugin không tự kéo dài**
- Trong grace: vẫn `can_mutate`, badge **Mất kết nối (grace)** — khác với **LOCKED — gói hết hạn**
- Backend trả `expired`, `canceled`, `suspended`, `revoked` → **khóa ngay**, không grace

Tests: `php tests/test_entitlement_lock.php`, `php tests/test_network_grace.php`

## HMAC (SEOAuto → plugin)

Required headers:

- `X-SEOAuto-Site-ID`
- `X-SEOAuto-Connection-ID`
- `X-SEOAuto-Timestamp`
- `X-SEOAuto-Nonce`
- `X-SEOAuto-Request-ID`
- `X-SEOAuto-Signature`

Canonical string (LF):

```
METHOD
PATH
TIMESTAMP
NONCE
REQUEST_ID
SHA256_HEX(body)
```

Signature = `hex(HMAC-SHA256(site_secret, canonical))`.

Tests: `php tests/test_hmac_auth.php`
