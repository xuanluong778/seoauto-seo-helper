#!/usr/bin/env bash
# Chain: stock 1.0.4 -> bridge 1.0.5 -> private update to 1.1.0-rc.1 on site1 only.
set -euo pipefail
WP=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
STOCK=/tmp/seoauto-seo-helper-1.0.4-stock.zip
BRIDGE="${1:?bridge zip path}"
EXPECT_FINAL=1.1.0-rc.1

test -f "$STOCK"
test -f "$BRIDGE"

PRE_SITE=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("site_id","");' --path="$WP" --allow-root 2>/dev/null || true)
PRE_POSTS=$(wp post list --post_type=post --format=count --path="$WP" --allow-root)

echo "=== stock 1.0.4 ==="
wp plugin deactivate seoauto-seo-helper --path="$WP" --allow-root || true
wp plugin delete seoauto-seo-helper --path="$WP" --allow-root || true
rm -rf "$WP/wp-content/plugins/seoauto-seo-helper"
wp plugin install "$STOCK" --force --activate --path="$WP" --allow-root
test "$(wp plugin get seoauto-seo-helper --field=version --path=$WP --allow-root)" = "1.0.4"
if wp eval 'echo class_exists("SEOAuto\\SEOHelper\\Updater\\Update_Manager")?"yes":"no";' --path="$WP" --allow-root | grep -q yes; then
  echo "FAIL stock has updater"
  exit 1
fi
echo "stock_no_updater_ok"

echo "=== bridge 1.0.5 ==="
wp plugin install "$BRIDGE" --force --activate --path="$WP" --allow-root
test "$(wp plugin get seoauto-seo-helper --field=version --path=$WP --allow-root)" = "1.0.5"
wp eval 'exit(class_exists("SEOAuto\\SEOHelper\\Updater\\Update_Manager")?0:1);' --path="$WP" --allow-root
wp eval '
$cm=SEOAuto\SEOHelper\Plugin::instance()->connection();
$cm->update_option("update_channel","beta");
echo "status=", $cm->option("status",""), " api=", $cm->api_base(), " site=", $cm->option("site_id",""), "\n";
' --path="$WP" --allow-root

# Ensure published rc.1 available
TOKEN=$(grep '^WP_PLUGIN_CI_RELEASE_TOKEN=' /var/www/seoauto_vn_usr/data/www-staging/env.local | cut -d= -f2-)
curl -sS -X POST "http://127.0.0.1:8901/api/wordpress-plugin/releases/1.1.0-rc.1/publish?channel=beta" \
  -H "Content-Type: application/json" -H "X-SEOAuto-CI-Token: $TOKEN" -d '{}' >/tmp/rc1_pub.json || true
python3 - <<'PY'
import json
try:
  d=json.load(open("/tmp/rc1_pub.json"))
  print("rc1_publish", (d.get("release") or {}).get("status") or d.get("detail"))
except Exception as e:
  print("rc1_publish_parse", type(e).__name__)
PY

echo "=== update to rc.1 ==="
wp eval '
include_once ABSPATH."wp-admin/includes/class-wp-upgrader.php";
include_once ABSPATH."wp-admin/includes/file.php";
include_once ABSPATH."wp-admin/includes/misc.php";
include_once ABSPATH."wp-admin/includes/plugin.php";
$u=SEOAuto\SEOHelper\Plugin::instance()->updater();
$r=$u->force_check();
if (is_wp_error($r) || empty($r->package)) { fwrite(STDERR, is_wp_error($r)?$r->get_error_message():"no package"); exit(1);} 
echo "to=", $r->version, "\n";
$upgrader=new Plugin_Upgrader(new Automatic_Upgrader_Skin());
$res=$upgrader->run(array(
  "package"=>$r->package,
  "destination"=>WP_PLUGIN_DIR."/seoauto-seo-helper",
  "clear_destination"=>true,
  "clear_working"=>true,
  "hook_extra"=>array("plugin"=>"seoauto-seo-helper/seoauto-seo-helper.php","type"=>"plugin","action"=>"update"),
));
if (is_wp_error($res) || $res===false) exit(1);
echo "UPGRADE_OK\n";
' --path="$WP" --allow-root

POST_VER=$(wp plugin get seoauto-seo-helper --field=version --path="$WP" --allow-root)
POST_SITE=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("site_id","");' --path="$WP" --allow-root)
POST_POSTS=$(wp post list --post_type=post --format=count --path="$WP" --allow-root)
ACTIVE=$(wp plugin is-active seoauto-seo-helper --path="$WP" --allow-root && echo yes || echo no)
echo "POST ver=$POST_VER site=$POST_SITE posts=$POST_POSTS active=$ACTIVE pre_posts=$PRE_POSTS"
test "$POST_VER" = "$EXPECT_FINAL"
test "$ACTIVE" = "yes"
test "$POST_POSTS" = "$PRE_POSTS"
if [[ -n "$PRE_SITE" ]]; then test "$POST_SITE" = "$PRE_SITE"; fi
echo CHAIN_104_105_RC1_PASS
