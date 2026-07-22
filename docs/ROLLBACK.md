# Rollback — SEOAuto SEO Helper

## Rollback plugin (giữ dữ liệu WP)

1. Deactivate plugin hiện tại
2. Cài lại bản ZIP trước đó (hoặc restore từ backup `wp-content/plugins/seoauto-seo-helper/`)
3. Activate

**Giữ nguyên:** bài viết, media, options pairing, bảng idempotency.

## Rollback schema (chỉ khi gỡ hoàn toàn)

Chạy trên DB backup đã xác nhận. Thay `wp_` bằng prefix thực tế.

```sql
DROP TABLE IF EXISTS wp_seoauto_helper_idempotency;
DROP TABLE IF EXISTS wp_seoauto_helper_article_map;
DROP TABLE IF EXISTS wp_seoauto_helper_media_map;
DELETE FROM wp_options WHERE option_name LIKE 'seoauto_helper_%';
```

Hoặc: **Xóa plugin** trong WP Admin (uninstall.php tự chạy).

## Rollback pairing / secret

**Ngắt kết nối:** SEOAuto Helper → Kết nối → **Ngắt kết nối**

Xóa `site_secret` khỏi site; cần mã SA mới để ghép lại.

## Rollback entitlement lock

- Không cần cài lại plugin
- User nâng cấp gói trên SEOAuto → entitlement hợp lệ → auto unlock
- Hoặc **Kiểm tra lại gói** / SaaS push `POST /entitlement/refresh`

## Rollback sau lỗi Wordfence

1. Wordfence → Tools → Live Traffic
2. Tìm 403 tới `/wp-json/seoauto/v1/`
3. Admin tự allowlist nếu cần — plugin không tự bypass

## Khôi phục từ backup site

1. Restore files + database
2. Kiểm tra `seoauto_helper_status` = `connected`
3. **Kiểm tra kết nối** trong admin

## Không rollback tự động

Plugin **không**:

- Tự deactivate khi LOCKED
- Xóa bài/ảnh khi hết hạn gói
- Sửa wp-config / tắt plugin bảo mật khác
