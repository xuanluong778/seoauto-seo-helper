# Bridge SEOAuto SEO Helper 1.0.5 — hướng dẫn cài thủ công

Dành cho website đang dùng **v1.0.4 gốc** (chưa có Private Updater). Cài **một lần** để bật cập nhật riêng từ SEOAuto, rồi dùng “Kiểm tra cập nhật” / “Cập nhật ngay” để lên RC/stable.

## Artifact

- Thư mục mới nhất: xem `artifacts/bridge-1.0.5/LATEST.txt`
- File: `seoauto-seo-helper-1.0.5-bridge.zip`
- Kiểm tra: `VERIFY.txt` + `.sha256` (không ghi đè bản cũ — mỗi build một thư mục timestamp)

## Cài đặt (WordPress Admin)

1. **Plugins → Add New → Upload Plugin** → chọn ZIP bridge `1.0.5`.
2. **Replace current with uploaded** nếu đang có `1.0.4`.
3. Giữ plugin **Active**.
4. Vào SEO Helper → xác nhận vẫn **paired** với SEOAuto (staging hoặc production tùy site).
5. Chọn kênh cập nhật **beta** nếu đang thử RC; **stable** khi đã phát hành chính thức.
6. Bấm **Kiểm tra cập nhật** → **Cập nhật ngay** khi có bản mới (ví dụ `1.1.0-rc.1` / `1.1.0`).

## WP-CLI

```bash
wp plugin install /path/to/seoauto-seo-helper-1.0.5-bridge.zip --force --activate
wp eval 'echo SEOAUTO_HELPER_VERSION, " ", class_exists("SEOAuto\\SEOHelper\\Updater\\Update_Manager")?"updater":"no";'
```

## Kiểm tra nhanh

| Kiểm tra | Kỳ vọng |
|----------|---------|
| Version | `1.0.5` |
| Updater | class `Update_Manager` tồn tại |
| Pairing | `connected`, không mất `site_id` |
| Không có | thư mục SeoAudit / `.env` / tests trong ZIP |

## Lưu ý

- Bridge **chỉ** thêm Private Updater + verifier trên nền `1.0.4`; không đổi Billing / Credit / HMAC.
- Không dùng bridge này để “downgrade” từ RC/stable.
- Sau khi lên `1.1.0+` qua updater, không cần cài lại bridge.
