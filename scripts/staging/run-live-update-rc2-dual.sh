#!/usr/bin/env bash
# Update both staging WP sites to CI-published 1.2.0-rc.2 via private updater.
set -euo pipefail
EXPECT_VER="${1:-1.2.0-rc.2}"
WP1=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
WP2=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper2-staging.siteauto.vn

update_site() {
  local WP="$1" NAME="$2"
  echo "==== LIVE UPDATE $NAME -> $EXPECT_VER ===="
  local PRE_SITE PRE_CONN PRE_POSTS PRE_MEDIA PRE_VER
  PRE_SITE=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("site_id","");' --path="$WP" --allow-root)
  PRE_CONN=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("connection_id","");' --path="$WP" --allow-root)
  PRE_POSTS=$(wp post list --post_type=post --format=count --path="$WP" --allow-root)
  PRE_MEDIA=$(wp post list --post_type=attachment --format=count --path="$WP" --allow-root)
  PRE_VER=$(wp plugin get seoauto-seo-helper --field=version --path="$WP" --allow-root)
  echo "PRE ver=$PRE_VER posts=$PRE_POSTS media=$PRE_MEDIA"

  if [[ "$PRE_VER" == "$EXPECT_VER" ]]; then
    echo "already_on_target"
  else
    wp eval '
$cm=SEOAuto\SEOHelper\Plugin::instance()->connection();
$cm->update_option("update_channel","beta");
$u=SEOAuto\SEOHelper\Plugin::instance()->updater();
$r=$u->force_check();
if (is_wp_error($r)) { echo "CHECK_ERR ", $r->get_error_code()," ",$r->get_error_message(),"\n"; exit(1);} 
echo "available=", !empty($r->update_available)?"1":"0", " version=", $r->version, "\n";
if (empty($r->update_available) || empty($r->package)) { echo "NO_UPDATE\n"; exit(1);} 
include_once ABSPATH."wp-admin/includes/class-wp-upgrader.php";
include_once ABSPATH."wp-admin/includes/file.php";
include_once ABSPATH."wp-admin/includes/misc.php";
include_once ABSPATH."wp-admin/includes/plugin.php";
$skin=new Automatic_Upgrader_Skin();
$upgrader=new Plugin_Upgrader($skin);
$result=$upgrader->run(array(
  "package"=>$r->package,
  "destination"=>WP_PLUGIN_DIR."/seoauto-seo-helper",
  "clear_destination"=>true,
  "clear_working"=>true,
  "hook_extra"=>array("plugin"=>"seoauto-seo-helper/seoauto-seo-helper.php","type"=>"plugin","action"=>"update"),
));
if (is_wp_error($result) || $result===false) { echo "UPGRADE_ERR\n"; exit(1);} 
echo "UPGRADE_OK\n";
' --path="$WP" --allow-root
  fi

  local POST_VER POST_SITE POST_CONN POST_POSTS POST_MEDIA POST_ACTIVE POST_DB
  POST_VER=$(wp plugin get seoauto-seo-helper --field=version --path="$WP" --allow-root)
  POST_ACTIVE=$(wp plugin is-active seoauto-seo-helper --path="$WP" --allow-root && echo yes || echo no)
  POST_SITE=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("site_id","");' --path="$WP" --allow-root)
  POST_CONN=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("connection_id","");' --path="$WP" --allow-root)
  POST_POSTS=$(wp post list --post_type=post --format=count --path="$WP" --allow-root)
  POST_MEDIA=$(wp post list --post_type=attachment --format=count --path="$WP" --allow-root)
  POST_DB=$(wp eval 'echo get_option("seoauto_helper_db_version","");' --path="$WP" --allow-root)
  wp eval 'SEOAuto\SEOHelper\Post\Schema::maybe_upgrade();' --path="$WP" --allow-root || true
  echo "POST ver=$POST_VER active=$POST_ACTIVE db=$POST_DB posts=$POST_POSTS media=$POST_MEDIA"
  test "$POST_VER" = "$EXPECT_VER"
  test "$POST_SITE" = "$PRE_SITE"
  test "$POST_CONN" = "$PRE_CONN"
  test "$POST_ACTIVE" = "yes"
  test "$POST_POSTS" = "$PRE_POSTS"
  test "$POST_MEDIA" = "$PRE_MEDIA"
  test "$POST_DB" = "4"
  echo "LIVE_UPDATE_PASS $NAME"
}

update_site "$WP1" site1
update_site "$WP2" site2
# keep Yoast on site2
wp plugin activate wordpress-seo --path="$WP2" --allow-root || true
wp plugin deactivate seo-by-rank-math --path="$WP2" --allow-root || true
echo DUAL_LIVE_UPDATE_RC_PASS
