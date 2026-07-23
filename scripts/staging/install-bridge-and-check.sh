#!/usr/bin/env bash
set -euo pipefail
WP=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
BRIDGE_ZIP="${1:?bridge zip with updater but version 1.0.4}"
wp plugin install "$BRIDGE_ZIP" --force --activate --path="$WP" --allow-root
wp eval '
$cm = SEOAuto\SEOHelper\Plugin::instance()->connection();
$cm->update_option("update_channel", "beta");
echo "version=", SEOAUTO_HELPER_VERSION, "\n";
echo "status=", $cm->option("status",""), "\n";
echo "channel=", $cm->option("update_channel","stable"), "\n";
echo "site_id=", $cm->option("site_id",""), "\n";
echo "has_updater=", class_exists("SEOAuto\\SEOHelper\\Updater\\Update_Manager") ? "yes" : "no", "\n";
' --path="$WP" --allow-root
# Force check
wp eval '
$u = SEOAuto\SEOHelper\Plugin::instance()->updater();
$r = $u->force_check();
if (is_wp_error($r)) { echo "ERR ", $r->get_error_code(), " ", $r->get_error_message(), "\n"; exit(1); }
echo "update_available=", $r->update_available ? "1" : "0", "\n";
echo "new_version=", $r->version, "\n";
echo "package_host=", parse_url($r->package, PHP_URL_HOST), "\n";
' --path="$WP" --allow-root
echo BRIDGE_OK
