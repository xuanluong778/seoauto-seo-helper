#!/usr/bin/env bash
set -euo pipefail
WP=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
CODE="${1:?pairing code}"
API="${2:-https://seoauto-api-staging.siteauto.vn}"
export SEOAUTO_CODE="$CODE"
export SEOAUTO_API="$API"
wp eval '
$code = getenv("SEOAUTO_CODE");
$api = getenv("SEOAUTO_API");
if (!class_exists("SEOAuto\\SEOHelper\\Plugin")) {
  require_once WP_PLUGIN_DIR . "/seoauto-seo-helper/seoauto-seo-helper.php";
}
$p = SEOAuto\SEOHelper\Plugin::instance();
$cm = $p->connection();
$res = $cm->pair_with_code($code, $api);
echo wp_json_encode($res, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";
echo "status=", $cm->option("status",""), "\n";
echo "site_id=", $cm->option("site_id",""), "\n";
echo "connection_id=", $cm->option("connection_id",""), "\n";
echo "api_base=", $cm->api_base(), "\n";
echo "version=", defined("SEOAUTO_HELPER_VERSION") ? SEOAUTO_HELPER_VERSION : "?", "\n";
' --path="$WP" --allow-root
echo PAIR_DONE
