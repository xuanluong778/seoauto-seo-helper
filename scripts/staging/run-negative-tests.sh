#!/usr/bin/env bash
set -euo pipefail
WP=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
TOKEN=$(grep '^WP_PLUGIN_CI_RELEASE_TOKEN=' /var/www/seoauto_vn_usr/data/www-staging/env.local | cut -d= -f2-)

echo "=== withdraw release ==="
curl -sS -X POST "http://127.0.0.1:8901/api/wordpress-plugin/releases/1.1.0-beta.1/withdraw" \
  -H "Content-Type: application/json" -H "X-SEOAuto-CI-Token: $TOKEN" \
  -d '{"channel":"beta","reason":"e2e withdraw test"}' | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d["release"]["status"])'

echo "=== update check after withdraw (expect none) ==="
wp eval '
$u=SEOAuto\SEOHelper\Plugin::instance()->updater();
$r=$u->force_check();
if (is_wp_error($r)) { echo "err ", $r->get_error_code(), "\n"; exit(0);} 
echo "available=", !empty($r->update_available)?"1":"0", "\n";
' --path="$WP" --allow-root

echo "=== re-publish for further tests ==="
curl -sS -X POST "http://127.0.0.1:8901/api/wordpress-plugin/releases/1.1.0-beta.1/publish?channel=beta" \
  -H "Content-Type: application/json" -H "X-SEOAuto-CI-Token: $TOKEN" -d '{}' | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d.get("release",{}).get("status") or d)'

echo "=== LOCKED still can check ==="
wp eval '
$cm=SEOAuto\SEOHelper\Plugin::instance()->connection();
$cm->update_option("status", SEOAuto\SEOHelper\Connection\Connection_Manager::STATUS_LOCKED);
$u=SEOAuto\SEOHelper\Plugin::instance()->updater();
$r=$u->force_check();
echo is_wp_error($r) ? ("err ".$r->get_error_code()) : ("locked_check_ok available=".( !empty($r->update_available)?"1":"0")), "\n";
$cm->update_option("status", SEOAuto\SEOHelper\Connection\Connection_Manager::STATUS_CONNECTED);
' --path="$WP" --allow-root

echo "=== verifier gates via php unit on server ==="
# reuse plugin verifier via wp eval
wp eval '
$v=new SEOAuto\SEOHelper\Updater\Package_Verifier();
$checks=[];
$checks["downgrade"]=is_wp_error($v->assert_newer_version("1.1.0-beta.1","1.0.4"));
$checks["replay"]=is_wp_error($v->assert_newer_version("1.1.0-beta.1","1.1.0-beta.1"));
$checks["http"]=is_wp_error($v->assert_safe_package_url("http://seoauto.vn/x.zip"));
$checks["badhost"]=is_wp_error($v->assert_safe_package_url("https://evil.example/x.zip"));
foreach($checks as $k=>$ok) echo $k, "=", $ok?"block":"FAIL", "\n";
' --path="$WP" --allow-root

echo "=== wordfence still active ==="
wp plugin is-active wordfence --path="$WP" --allow-root && echo wordfence_active=yes
systemctl is-active digiseo digiseo-staging
echo NEGATIVE_OK
