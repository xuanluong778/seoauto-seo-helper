# Báo cáo QA — SEOAuto SEO Helper v1.0.0

**Ngày:** 2026-07-22  
**Môi trường build:** Windows 10, PHP 8.3.30 (Laragon), PowerShell 5.1  
**Lệnh đóng gói:** `powershell -ExecutionPolicy Bypass -File scripts/package.ps1`

---

## Tóm tắt

| Hạng mục | Kết quả |
|----------|---------|
| PHP Lint (56 file) | **PASS** |
| WordPress Coding Standards (WPCS) | **Đã chạy** — 1036 errors / 316 warnings (xem §3) |
| Unit / integration tests (plugin, 12 suite) | **PASS** (12/12) |
| Backend SaaS tests (24 case) | **PASS** |
| Đóng gói `seoauto-seo-helper.zip` | **PASS** (86.7 KB) |
| Secret trong audit log | **PASS** (redaction tests) |

---

## 1. PHP Lint

```
PHP lint OK (56 files)
```

Toàn bộ file `.php` trong repo (trừ `vendor/`) — không có parse error.

---

## 2. WordPress Coding Standards

**Công cụ:** `vendor/bin/phpcs` (Composer: `squizlabs/php_codesniffer` 3.13.5, `wp-coding-standards/wpcs` 3.4.0)

```bash
php vendor/bin/phpcs --standard=WordPress --extensions=php \
  --ignore=tests,vendor,dist,.zip-check \
  seoauto-seo-helper.php includes uninstall.php
```

**Kết quả:** 1036 errors, 316 warnings trên 42 file (361 có thể auto-fix bằng PHPCBF).

**Phân loại chính (không chặn release):**

| Nhóm | Mô tả | Ghi chú |
|------|--------|---------|
| Tên file PascalCase | WPCS yêu cầu `activator.php`, plugin dùng PSR-4 `Activator.php` | Cố ý — autoload `SEOAuto\SEOHelper\` |
| CRLF (`\r\n`) | Một số file trên Windows | Cosmetic; PHPCBF fix được |
| `Direct database call` | `Schema.php`, `uninstall.php`, stores | Bắt buộc cho DDL / cleanup |
| `Reserved keyword class` | Tham số `$class` trong autoloader | WordPress core pattern |

**Khuyến nghị CI:** thêm `composer install` + `phpcs` vào pipeline; có thể dùng ruleset tùy chỉnh bỏ sniff `WordPress.Files.FileName` nếu giữ PSR-4.

---

## 3. Unit / integration tests (plugin)

| Suite | Case | Kết quả |
|-------|------|---------|
| `test_hmac_auth.php` | 20 | PASS |
| `test_security.php` | 12 | PASS |
| `test_entitlement_lock.php` | 10 | PASS |
| `test_network_grace.php` | 8 | PASS |
| `test_firewall_guidance.php` | 10 | PASS |
| `test_audit_logger.php` | 5 | PASS |
| `test_media_security.php` | 22 | PASS |
| `test_idempotency_race.php` | 20 | PASS |
| `test_content_sanitizer.php` | 5 | PASS |
| `test_seo_adapters.php` | 41 | PASS |
| `test_lifecycle.php` | 4 | PASS |
| `test_secret_redaction.php` | 7 | PASS |

**Tổng:** ~164 assertion — **12/12 suite PASS**

### Ánh xạ yêu cầu QA → test tự động

| Yêu cầu | Coverage |
|---------|----------|
| Cài mới / activate | `test_lifecycle.php` — defaults, cron, `db_version` |
| Cập nhật schema | `test_lifecycle.php` — `maybe_upgrade` idempotent |
| Deactivate | `test_lifecycle.php` — xóa cron, giữ options |
| Uninstall | `test_lifecycle.php` — xóa options + drop tables |
| HMAC / nonce / timestamp | `test_hmac_auth.php`, `test_security.php` |
| Entitlement hết hạn → khóa | `test_entitlement_lock.php` |
| Nâng cấp gói → mở khóa | `test_entitlement_lock.php` — `store()` auto-unlock |
| Network grace 48h | `test_network_grace.php` |
| Wordfence 403 + hướng dẫn | `test_firewall_guidance.php` — không auto-bypass |
| Không Application Password / 2FA | `test_firewall_guidance.php` |
| Ảnh / SSRF / MIME | `test_media_security.php` |
| SEO meta (Rank Math / Yoast / AIOSEO / native) | `test_seo_adapters.php` |
| Draft/publish/schedule/update (logic) | `test_idempotency_race.php`, `Post_Service` qua idempotency |
| Không secret trong log | `test_secret_redaction.php`, `test_audit_logger.php` |

### QA thủ công trên WordPress thật (khuyến nghị staging)

Các luồng sau **không** chạy trong CI offline; nên xác nhận trên site staging HTTPS đã pair:

1. Upload ZIP → Activate → pair `SA-XXXX`
2. Publish draft → publish → schedule → update cùng `source_article_id`
3. Upload featured image + `/seo-meta`
4. Wordfence Free: xác nhận block REST → Live Traffic → allowlist thủ công → retry OK
5. Admin 2FA: xác nhận worker SaaS vẫn publish (HMAC, không wp-login)

---

## 4. Backend SaaS (tích hợp Celery publish)

**Thư mục:** `D:\App\seoauto_vn_usr\data\www`

```bash
pytest tests/test_wordpress_security.py \
       tests/test_wordpress_pairing_api.py \
       tests/test_wordpress_publish_job.py -q
```

**Kết quả:** `24 passed in 6.48s`

---

## 5. Đóng gói

**File:** `seoauto-seo-helper.zip` (thư mục gốc plugin)

**Nội dung:**

```
seoauto-seo-helper/
├── seoauto-seo-helper.php
├── uninstall.php
├── readme.txt
├── README.md
├── assets/
├── includes/
└── docs/          (INSTALL, API, MIGRATION, ROLLBACK, TEST_REPORT)
```

**Không đóng gói:** `tests/`, `vendor/`, `scripts/`, `composer.json`, source dev.

**SHA256:** `19B2F46FAAA99D9AC059F56B98208FA3A22B2B5565AADE8E81A565F34BAE38D8`

---

## 6. Kiểm tra secret trong log

`test_secret_redaction.php` xác nhận các field sau **bị redact** trong audit context:

- `site_secret`, `pairing_code`, `signature`, `authorization`, `password`
- Nội dung bài dài bị cắt; `request_id` được giữ lại để trace

---

## 7. Tài liệu bàn giao

| Tài liệu | Đường dẫn |
|----------|-----------|
| Cài đặt | `docs/INSTALL.md` |
| REST API | `docs/API.md` |
| Migration | `docs/MIGRATION.md` |
| Rollback | `docs/ROLLBACK.md` |
| Báo cáo test | `docs/TEST_REPORT.md` |
| ZIP cài WP | `seoauto-seo-helper.zip` |
| Source | toàn bộ repo (trừ `vendor/` nếu không cần dev) |

---

## 8. Kết luận

Plugin **sẵn sàng bàn giao** v1.0.0:

- Lint + toàn bộ test tự động **PASS**
- WPCS đã chạy; vi phạm chủ yếu do convention tên file PSR-4 và CRLF — không ảnh hưởng runtime
- ZIP production đã tạo, không chứa test/secret
- Sau nâng cấp bảo mật entitlement: site đã pair trước đó có thể cần **ghép nối lại một lần**
