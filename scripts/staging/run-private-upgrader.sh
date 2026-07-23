#!/usr/bin/env bash
set -euo pipefail
WP=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
START=$(date -Is)
echo "START=$START"
PRE_SITE=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("site_id","");' --path="$WP" --allow-root)
PRE_VER=$(wp plugin get seoauto-seo-helper --field=version --path="$WP" --allow-root)
PRE_POSTS=$(wp post list --post_type=post --format=count --path="$WP" --allow-root)
echo "PRE ver=$PRE_VER site=$PRE_SITE posts=$PRE_POSTS"

wp eval '
include_once ABSPATH . "wp-admin/includes/class-wp-upgrader.php";
include_once ABSPATH . "wp-admin/includes/file.php";
include_once ABSPATH . "wp-admin/includes/misc.php";
include_once ABSPATH . "wp-admin/includes/plugin.php";
$u = SEOAuto\SEOHelper\Plugin::instance()->updater();
$r = $u->force_check();
if (is_wp_error($r)) { fwrite(STDERR, $r->get_error_message()); exit(1); }
if (empty($r->update_available) || empty($r->package)) { fwrite(STDERR, "no package"); exit(1); }
echo "package_host=", parse_url($r->package, PHP_URL_HOST), " new=", $r->version, "\n";
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
if (is_wp_error($result)) { echo "UPGRADE_ERR ", $result->get_error_code(), " ", $result->get_error_message(), "\n"; exit(1); }
echo "UPGRADE_OK\n";
' --path="$WP" --allow-root

# reload plugin constants by re-including via CLI bootstrap already has new files
POST_VER=$(wp plugin get seoauto-seo-helper --field=version --path="$WP" --allow-root)
POST_ACTIVE=$(wp plugin is-active seoauto-seo-helper --path="$WP" --allow-root && echo yes || echo no)
POST_SITE=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("site_id","");' --path="$WP" --allow-root)
POST_STATUS=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("status","");' --path="$WP" --allow-root)
POST_POSTS=$(wp post list --post_type=post --format=count --path="$WP" --allow-root)
END=$(date -Is)
echo "END=$END"
echo "POST ver=$POST_VER active=$POST_ACTIVE site=$POST_SITE status=$POST_STATUS posts=$POST_POSTS"
wp eval 'SEOAuto\SEOHelper\Post\Schema::maybe_upgrade(); echo "migration_ok\n";' --path="$WP" --allow-root
curl -sk -o /dev/null -w "front %{http_code}\n" https://seohelper-staging.siteauto.vn/
wp plugin list --path="$WP" --allow-root | grep -E 'seoauto|wordfence|rank-math'
test "$POST_VER" = "1.1.0-beta.1"
test "$POST_SITE" = "$PRE_SITE"
test "$POST_ACTIVE" = "yes"
echo PRIVATE_UPDATE_PASS
