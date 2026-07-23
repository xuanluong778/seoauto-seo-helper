=== SEOAuto SEO Helper ===
Contributors: seoauto
Tags: seo, wordpress, seoauto, schema, open-graph
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.1.0-beta.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Káº¿t ná»‘i WordPress vá»›i SEOAuto â€” Open Graph, Schema, Ä‘á»“ng bá»™ Rank Math/Yoast.

== Description ==

SEOAuto SEO Helper ghÃ©p ná»‘i site WordPress vá»›i ná»n táº£ng SEOAuto qua mÃ£ má»™t láº§n (SA-XXXX-XXXX).

* REST `/wp-json/seoauto/v1`: status, connect, disconnect, entitlement/refresh, posts, media, seo-meta, health-check, logs (HMAC only)
* KhÃ´ng hard-code gÃ³i â€” entitlement do SEOAuto kÃ½ vÃ  Ä‘áº©y xuá»‘ng
* KhÃ´ng dÃ¹ng eval, khÃ´ng sá»­a wp-config.php, khÃ´ng táº¡o admin, khÃ´ng táº¯t plugin báº£o máº­t

== Installation ==

1. Upload thÆ° má»¥c `seoauto-seo-helper` vÃ o `/wp-content/plugins/`
2. KÃ­ch hoáº¡t plugin trong WordPress Admin
3. VÃ o Settings â†’ SEOAuto SEO Helper, nháº­p mÃ£ SA-XXXX-XXXX tá»« SEOAuto

== Changelog ==

= 1.1.0-dev =
* Phase 1: SEO Audit Engine (scan-only) â€” WP-Cron batch jobs, issue store, admin Audit/Jobs, REST /audit/* + /jobs/{id}.
* Feature gate: seo_audit. LOCKED blocks new scans; keeps historical results.
* Private Plugin Updater: Update URI seoauto.vn, HMAC update check, signed short-lived ZIP download, SHA-256 verify.

= 1.0.4 =
* Fix fatal error trÃªn PHP 8.1: thay kiá»ƒu `true|WP_Error` (chá»‰ cÃ³ tá»« PHP 8.2) báº±ng `bool|WP_Error`.

= 1.0.3 =
* Activation: bá»c try/catch, tá»± deactivate náº¿u lá»—i (trÃ¡nh khÃ³a wp-admin).
* Kiá»ƒm tra thÆ° má»¥c includes/ trÆ°á»›c khi boot.
* ÄÆ¡n giáº£n hÃ³a activate options (khÃ´ng query DB thÃ´).

= 1.0.2 =
* Fix critical error: main plugin file moved out of PHP namespace (WordPress bootstrap best practice).
* Rename REST NAMESPACE constant to REST_NAMESPACE.
* Check OpenSSL extension before boot.

= 1.0.1 =
* Fix fatal error on activation: WordPress constants (HOUR_IN_SECONDS, ABSPATH, â€¦) in namespaced code.
* Fix cron schedule registration during plugin activation.

= 1.0.0 =
* Initial release
