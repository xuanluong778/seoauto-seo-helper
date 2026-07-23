#!/usr/bin/env bash
# Pair site2 + update both staging WPs to 1.1.0-rc.1 via private updater.
set -euo pipefail
WP1=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
WP2=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper2-staging.siteauto.vn
API=https://staging.seoauto.vn
EXPECT=1.1.0-rc.1

echo "=== seed pairing for site2 ==="
OUT=$(sudo -u seoauto_vn_usr bash -lc 'cd /var/www/seoauto_vn_usr/data/www-staging && set -a && source env.local && set +a && .venv/bin/python - <<"PY"
from app.db import SessionLocal
from app.services.wordpress_pairing_service import create_pairing_code
import app.models.user, app.models.seo, app.models.knowledge
from app.models.user import User
db=SessionLocal()
try:
    user=db.query(User).filter(User.email=="staging-helper@seoauto.vn").first()
    assert user is not None
    out=create_pairing_code(db, user, domain_hint="seohelper2-staging.siteauto.vn")
    print(out["code"])
finally:
    db.close()
PY')
CODE=$(echo "$OUT" | tail -n1 | tr -d '\r')
echo "pairing_code_len=${#CODE}"

export SEOAUTO_CODE="$CODE" SEOAUTO_API="$API"
wp eval '
$code=getenv("SEOAUTO_CODE"); $api=getenv("SEOAUTO_API");
$cm=SEOAuto\SEOHelper\Plugin::instance()->connection();
$res=$cm->pair_with_code($code, $api);
echo "pair_ok=", is_wp_error($res)?"no":"yes", " status=", $cm->option("status",""), " site=", $cm->option("site_id",""), " api=", $cm->api_base(), "\n";
if (is_wp_error($res)) { echo $res->get_error_message(),"\n"; exit(1);} 
$cm->update_option("update_channel","beta");
' --path="$WP2" --allow-root

update_one() {
  local WP="$1" NAME="$2"
  echo "=== UPDATE $NAME ==="
  wp eval '$cm=SEOAuto\SEOHelper\Plugin::instance()->connection(); $cm->update_option("update_channel","beta"); echo "pre=", SEOAUTO_HELPER_VERSION, " api=", $cm->api_base(), " status=", $cm->option("status",""), "\n";' --path="$WP" --allow-root
  wp eval '
include_once ABSPATH."wp-admin/includes/class-wp-upgrader.php";
include_once ABSPATH."wp-admin/includes/file.php";
include_once ABSPATH."wp-admin/includes/misc.php";
include_once ABSPATH."wp-admin/includes/plugin.php";
$u=SEOAuto\SEOHelper\Plugin::instance()->updater();
$r=$u->force_check();
if (is_wp_error($r) || empty($r->package)) { echo "NO_PKG ", is_wp_error($r)?$r->get_error_message():"none", "\n"; exit(1);} 
echo "to=", $r->version, "\n";
$upgrader=new Plugin_Upgrader(new Automatic_Upgrader_Skin());
$res=$upgrader->run(array(
  "package"=>$r->package,
  "destination"=>WP_PLUGIN_DIR."/seoauto-seo-helper",
  "clear_destination"=>true,
  "clear_working"=>true,
  "hook_extra"=>array("plugin"=>"seoauto-seo-helper/seoauto-seo-helper.php","type"=>"plugin","action"=>"update"),
));
if (is_wp_error($res) || $res===false) { echo "FAIL\n"; exit(1);} 
echo "UPGRADE_OK\n";
' --path="$WP" --allow-root
  local VER ACTIVE
  VER=$(wp plugin get seoauto-seo-helper --field=version --path="$WP" --allow-root)
  ACTIVE=$(wp plugin is-active seoauto-seo-helper --path="$WP" --allow-root && echo yes || echo no)
  echo "$NAME ver=$VER active=$ACTIVE"
  test "$VER" = "$EXPECT"
  test "$ACTIVE" = "yes"
}

update_one "$WP1" site1
update_one "$WP2" site2
curl -sk -o /dev/null -w "site1 %{http_code}\n" https://wp-staging.seoauto.vn/
curl -sk -o /dev/null -w "site2 %{http_code}\n" https://seohelper2-staging.siteauto.vn/
echo RC1_DUAL_SITE_PASS
