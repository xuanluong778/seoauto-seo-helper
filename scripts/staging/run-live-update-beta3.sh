#!/usr/bin/env bash
# Simulate admin “Kiểm tra cập nhật” + “Cập nhật ngay” for beta.3 E2E.
set -euo pipefail
WP=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
EXPECT_VER="${1:-1.1.0-beta.3}"

echo "=== PRE ==="
PRE_SITE=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("site_id","");' --path="$WP" --allow-root)
PRE_STATUS=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("status","");' --path="$WP" --allow-root)
PRE_CONN=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("connection_id","");' --path="$WP" --allow-root)
PRE_VER=$(wp plugin get seoauto-seo-helper --field=version --path="$WP" --allow-root)
PRE_DB=$(wp eval 'echo get_option("seoauto_helper_db_version","");' --path="$WP" --allow-root)
PRE_POSTS=$(wp post list --post_type=post --format=count --path="$WP" --allow-root)
PRE_MEDIA=$(wp post list --post_type=attachment --format=count --path="$WP" --allow-root)
echo "PRE ver=$PRE_VER db=$PRE_DB site=$PRE_SITE conn=$PRE_CONN status=$PRE_STATUS posts=$PRE_POSTS media=$PRE_MEDIA"

# Ensure beta channel
wp eval '
$cm = SEOAuto\SEOHelper\Plugin::instance()->connection();
$cm->update_option("update_channel", "beta");
echo "channel=", $cm->option("update_channel",""), " api=", $cm->api_base(), "\n";
' --path="$WP" --allow-root

echo "=== CHECK UPDATE (Kiểm tra cập nhật) ==="
wp eval '
$u = SEOAuto\SEOHelper\Plugin::instance()->updater();
$r = $u->force_check();
if (is_wp_error($r)) { echo "CHECK_ERR ", $r->get_error_code(), " ", $r->get_error_message(), "\n"; exit(1); }
echo "available=", !empty($r->update_available)?"1":"0", " version=", $r->version, "\n";
if (empty($r->update_available) || empty($r->package)) { echo "NO_UPDATE\n"; exit(1); }
echo "package_host=", parse_url($r->package, PHP_URL_HOST), "\n";
' --path="$WP" --allow-root

echo "=== UPDATE NOW (Cập nhật ngay via Plugin_Upgrader) ==="
wp eval '
include_once ABSPATH . "wp-admin/includes/class-wp-upgrader.php";
include_once ABSPATH . "wp-admin/includes/file.php";
include_once ABSPATH . "wp-admin/includes/misc.php";
include_once ABSPATH . "wp-admin/includes/plugin.php";
$u = SEOAuto\SEOHelper\Plugin::instance()->updater();
$r = $u->force_check();
if (is_wp_error($r) || empty($r->package)) { fwrite(STDERR, "no package"); exit(1); }
$skin = new Automatic_Upgrader_Skin();
$upgrader = new Plugin_Upgrader($skin);
$result = $upgrader->run(array(
  "package" => $r->package,
  "destination" => WP_PLUGIN_DIR . "/seoauto-seo-helper",
  "clear_destination" => true,
  "clear_working" => true,
  "hook_extra" => array(
    "plugin" => "seoauto-seo-helper/seoauto-seo-helper.php",
    "type" => "plugin",
    "action" => "update",
  ),
));
if (is_wp_error($result) || $result === false) {
  echo "UPGRADE_ERR\n";
  if (is_wp_error($result)) echo $result->get_error_code(), " ", $result->get_error_message(), "\n";
  exit(1);
}
echo "UPGRADE_OK\n";
' --path="$WP" --allow-root

echo "=== POST ==="
POST_VER=$(wp plugin get seoauto-seo-helper --field=version --path="$WP" --allow-root)
POST_ACTIVE=$(wp plugin is-active seoauto-seo-helper --path="$WP" --allow-root && echo yes || echo no)
POST_SITE=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("site_id","");' --path="$WP" --allow-root)
POST_STATUS=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("status","");' --path="$WP" --allow-root)
POST_CONN=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("connection_id","");' --path="$WP" --allow-root)
POST_DB=$(wp eval 'echo get_option("seoauto_helper_db_version","");' --path="$WP" --allow-root)
POST_POSTS=$(wp post list --post_type=post --format=count --path="$WP" --allow-root)
POST_MEDIA=$(wp post list --post_type=attachment --format=count --path="$WP" --allow-root)
wp eval 'SEOAuto\SEOHelper\Post\Schema::maybe_upgrade(); echo "migration_ok\n";' --path="$WP" --allow-root || true
curl -sk -o /dev/null -w "front %{http_code}\n" "https://wp-staging.seoauto.vn/" || true
wp plugin list --path="$WP" --allow-root | grep -E 'seoauto|wordfence|rank-math' || true

echo "POST ver=$POST_VER active=$POST_ACTIVE site=$POST_SITE conn=$POST_CONN status=$POST_STATUS db=$POST_DB posts=$POST_POSTS media=$POST_MEDIA"
test "$POST_VER" = "$EXPECT_VER"
test "$POST_SITE" = "$PRE_SITE"
test "$POST_CONN" = "$PRE_CONN"
test "$POST_ACTIVE" = "yes"
test "$POST_POSTS" = "$PRE_POSTS"
echo LIVE_UPDATE_PASS
