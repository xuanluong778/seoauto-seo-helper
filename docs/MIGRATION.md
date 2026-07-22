# Database migration — SEOAuto SEO Helper

## Phiên bản schema

| `db_version` | Thay đổi |
|--------------|----------|
| 1 | `idempotency`, `article_map` |
| 2 | + `media_map` |

Option: `seoauto_helper_db_version`

## Tự động (khuyến nghị)

- **Activate:** `Schema::install()` + set version
- **Mỗi request boot:** `Schema::maybe_upgrade()` nếu version < `DB_VERSION`

Không cần chạy SQL thủ công trên cài mới/cập nhật thông thường.

## Bảng

### `wp_seoauto_helper_idempotency`

- `UNIQUE(request_id)` — chống đăng trùng Celery retry
- Trạng thái: `pending` | `completed` | `failed`

### `wp_seoauto_helper_article_map`

- `UNIQUE(connection_id, source_article_id)` → `post_id`

### `wp_seoauto_helper_media_map`

- `UNIQUE(connection_id, file_hash)`
- `UNIQUE(connection_id, source_image_id)`

## Nâng cấp thủ công (nếu cần)

```sql
-- Kiểm tra version
SELECT option_value FROM wp_options WHERE option_name = 'seoauto_helper_db_version';

-- Sau khi deploy plugin mới, kích hoạt lại plugin hoặc truy cập admin
-- để maybe_upgrade() chạy.
```

## Options migration

Plugin chỉ **thêm** options mới khi activate (`add_option` nếu chưa có). Không ghi đè pairing khi update.

Options quan trọng:

- `seoauto_helper_site_secret` — encrypted
- `seoauto_helper_entitlement_json` + `entitlement_sig`
- `seoauto_helper_db_version`

## Tương thích SaaS

- Publish jobs: `request_id` unique per organization trên backend
- Entitlement ký bằng `site_secret` tại `/pair` — plugin verify local
