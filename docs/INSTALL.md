# Cài đặt SEOAuto SEO Helper

## Yêu cầu

| Thành phần | Phiên bản tối thiểu |
|------------|---------------------|
| WordPress | 6.0 |
| PHP | 8.1 |
| HTTPS | Bắt buộc (trừ localhost dev) |
| OpenSSL | Cho mã hóa `site_secret` (AES-256-GCM) |

**Không cần:** Application Password, mật khẩu Administrator, mã 2FA — plugin dùng **pairing SA-XXXX + HMAC**.

Tương thích: Rank Math, Yoast, AIOSEO, Wordfence Free, LiteSpeed Cache, admin 2FA.

## Cài mới

1. Tải `seoauto-seo-helper.zip`
2. WordPress Admin → **Plugins → Add New → Upload Plugin**
3. Chọn ZIP → **Install Now** → **Activate**
4. Menu **SEOAuto Helper → Kết nối**
5. Nhập **SEOAuto API** (HTTPS, ví dụ `https://seoauto.vn`)
6. Tạo mã ghép nối trên SEOAuto → nhập `SA-XXXX-XXXX` → **Kết nối**

Plugin tự tạo bảng DB (`wp_seoauto_helper_*`) và cron 6 giờ.

### Lỗi «Tập tin của plugin không tồn tại» khi kích hoạt

URL kích hoạt sai thường có dạng `seoauto-seo-helper/seoauto-seo-helper/seoauto-seo-helper.php` (thư mục bị lồng 2–3 lần).

**Cách sửa:**

1. **Plugins** → xóa hẳn plugin SEOAuto SEO Helper (nếu có)
2. Qua FTP/cPanel: xóa thư mục `wp-content/plugins/seoauto-seo-helper/` (toàn bộ)
3. Upload lại `seoauto-seo-helper.zip` qua **Plugins → Add New → Upload** (không giải nén thủ công vào thư mục cũ)
4. Kích hoạt — đường dẫn đúng phải là `seoauto-seo-helper/seoauto-seo-helper.php`

Cấu trúc sau khi giải nén đúng:

```
wp-content/plugins/seoauto-seo-helper/
├── seoauto-seo-helper.php   ← file chính
├── includes/
├── assets/
└── ...
```

## Cập nhật

1. **Deactivate** không bắt buộc — có thể upload ZIP đè trực tiếp
2. Upload `seoauto-seo-helper.zip` (cùng slug thư mục)
3. **Activate** lại nếu đã deactivate
4. `Schema::maybe_upgrade()` chạy khi boot — không mất pairing

Sau cập nhật bảo mật entitlement (ký `site_secret`): nếu cache cũ không verify được, **ghép nối lại** một lần.

## Deactivate

- Xóa cron đồng bộ entitlement
- **Giữ** options, `site_secret` mã hóa, bảng idempotency
- REST SEOAuto ngừng nhận lệnh (plugin không boot đầy đủ khi inactive)

## Gỡ cài đặt (Uninstall)

Chỉ khi **xóa plugin** khỏi WordPress (không chỉ deactivate):

- Xóa toàn bộ options `seoauto_helper_*`
- Drop bảng: `idempotency`, `article_map`, `media_map`
- **Không** xóa bài viết/ảnh đã đăng trên WP

## Wordfence + 2FA

- Wordfence có thể chặn REST `/wp-json/seoauto/v1/*` → xem **Live Traffic** thủ công
- Plugin **không** tự tắt Wordfence / allowlist IP
- Admin 2FA không ảnh hưởng HMAC (SaaS worker không dùng wp-admin)

## Kiểm tra sau cài

1. **SEOAuto Helper → Kết nối** → **Kiểm tra kết nối** → OK
2. **Tổng quan** → trạng thái **Đã kết nối**, plugin SEO, Wordfence
3. Từ SEOAuto: đăng thử 1 bài → **Nhật ký** có `post_create` + `request_id`

## Hỗ trợ

- Tài liệu API: `docs/API.md`
- Migration DB: `docs/MIGRATION.md`
- Rollback: `docs/ROLLBACK.md`
