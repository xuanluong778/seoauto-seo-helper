#!/usr/bin/env bash
set -euo pipefail
WP=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
python3 - <<'PY'
import zipfile
z=zipfile.ZipFile('/tmp/seoauto-seo-helper-1.0.4-bridge.zip')
assert 'seoauto-seo-helper/seoauto-seo-helper.php' in z.namelist()
print('zipok', len(z.namelist()))
PY
wp plugin install /tmp/seoauto-seo-helper-1.0.4-bridge.zip --force --activate --path="$WP" --allow-root
wp eval 'echo SEOAUTO_HELPER_VERSION, " ", (class_exists("SEOAuto\\SEOHelper\\Updater\\Update_Manager")?"updater":"no"), "\n";' --path="$WP" --allow-root
# preserve pairing, set beta channel
wp eval '
$cm = SEOAuto\SEOHelper\Plugin::instance()->connection();
$cm->update_option("update_channel", "beta");
echo "status=", $cm->option("status",""), " site=", $cm->option("site_id",""), " api=", $cm->api_base(), "\n";
$u = SEOAuto\SEOHelper\Plugin::instance()->updater();
$r = $u->force_check();
if (is_wp_error($r)) { echo "CHECK_ERR ", $r->get_error_code(), " ", $r->get_error_message(), "\n"; exit(1);} 
echo "available=", !empty($r->update_available)?"1":"0", " version=", $r->version, "\n";
' --path="$WP" --allow-root
echo BRIDGE_INSTALLED
