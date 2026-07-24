=== SEOAuto SEO Helper ===
Contributors: seoauto
Tags: seo, wordpress, seoauto, schema, open-graph
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.2.0-rc.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Kết nối WordPress với SEOAuto — Open Graph, Schema, đồng bộ Rank Math/Yoast.

== Description ==

SEOAuto SEO Helper ghép nối site WordPress với nền tảng SEOAuto qua mã một lần (SA-XXXX-XXXX).

* REST `/wp-json/seoauto/v1`: status, connect, disconnect, entitlement/refresh, posts, media, seo-meta, health-check, logs (HMAC only)
* Phase 2 ContentOps: Preview → Backup → Apply → Recheck → Rollback
* Không hard-code gói — entitlement do SEOAuto ký và đẩy xuống
* Không dùng eval, không sửa wp-config.php, không tạo admin, không tắt plugin bảo mật

== Installation ==

1. Upload thư mục `seoauto-seo-helper` vào `/wp-content/plugins/`
2. Kích hoạt plugin trong WordPress Admin
3. Vào Settings → SEOAuto SEO Helper, nhập mã SA-XXXX-XXXX từ SEOAuto

== Changelog ==

= 1.2.0-rc.4 =
* SEO Audit: gate UI by seo_audit feature with clear deny reason.
* SEO Audit: block duplicate active jobs (queued/retrying/running).
* SEO Audit: fail enqueue on DB insert errors (no false success).
* SEO Audit: reclaim stale running jobs after lock TTL; atomic claim; fail past max_attempts.

= 1.2.0-rc.3 =
* Simplified admin UI (connect/version/update). ContentOps gated as Sửa SEO & Khôi phục.

= 1.2.0-rc.2 =
* Release candidate (CI-clean): DB_VERSION test aligned to 4.

= 1.2.0-rc.1 =
* Release candidate for ContentOps Phase 2 (tag kept; CI QA blocked on stale DB_VERSION assert).

= 1.2.0-dev =
* Phase 2 ContentOps: Preview (read-only) → Backup → Apply (queue/idempotent/locks) → Recheck → Rollback.
* Full post snapshot: title, content, excerpt, slug, status, taxonomies, featured image, custom fields, SEO meta (Rank Math/Yoast/AIOSEO/native).
* Backup failure blocks Apply; rollback conflict when post changed after apply; 30-day backup retention + cron purge.
* Feature gate: content_ops. Does not modify Billing, HMAC, pairing, or updater.

= 1.1.0-rc.1 =
* Phase 1 release candidate: SEO Audit + Private Plugin Updater on staging.

= 1.1.0-dev =
* Phase 1: SEO Audit Engine (scan-only) — WP-Cron batch jobs, issue store, admin Audit/Jobs, REST /audit/* + /jobs/{id}.
* Feature gate: seo_audit. LOCKED blocks new scans; keeps historical results.
* Private Plugin Updater: Update URI seoauto.vn, HMAC update check, signed short-lived ZIP download, SHA-256 verify.

= 1.0.4 =
* Fix fatal error trên PHP 8.1: thay kiểu `true|WP_Error` (chỉ có từ PHP 8.2) bằng `bool|WP_Error`.

= 1.0.3 =
* Activation: bọc try/catch, tự deactivate nếu lỗi (tránh khóa wp-admin).
* Kiểm tra thư mục includes/ trước khi boot.
* Đơn giản hóa activate options (không query DB thô).

= 1.0.2 =
* Fix critical error: main plugin file moved out of PHP namespace (WordPress bootstrap best practice).
* Rename REST NAMESPACE constant to REST_NAMESPACE.
* Check OpenSSL extension before boot.

= 1.0.1 =
* Fix fatal error on activation: WordPress constants (HOUR_IN_SECONDS, ABSPATH, …) in namespaced code.
* Fix cron schedule registration during plugin activation.

= 1.0.0 =
* Initial release
