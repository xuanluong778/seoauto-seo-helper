#!/usr/bin/env bash
# Negative updater gates for RC without withdrawing the published RC artifact.
set -euo pipefail
WP=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
TOKEN=$(grep '^WP_PLUGIN_CI_RELEASE_TOKEN=' /var/www/seoauto_vn_usr/data/www-staging/env.local | cut -d= -f2-)
BASE=http://127.0.0.1:8901
DUMMY_VER=9.9.9-neg-e2e

echo "=== Package_Verifier gates (checksum/signature/expiry/replay/downgrade) ==="
wp eval '
$v=new SEOAuto\SEOHelper\Updater\Package_Verifier();
$checks=[];
$checks["downgrade"]=is_wp_error($v->assert_newer_version("1.2.0-rc.2","1.0.4"));
$checks["replay"]=is_wp_error($v->assert_newer_version("1.2.0-rc.2","1.2.0-rc.2"));
$checks["http"]=is_wp_error($v->assert_safe_package_url("http://seoauto.vn/x.zip"));
$checks["badhost"]=is_wp_error($v->assert_safe_package_url("https://evil.example/x.zip"));
$tmp=tempnam(sys_get_temp_dir(),"seoauto_chk");
file_put_contents($tmp,"payload");
$checks["bad_checksum"]=is_wp_error($v->assert_sha256_file($tmp, str_repeat("a",64)));
$checks["bad_sig"]=is_wp_error($v->assert_release_signature("deadbeef", "site-secret", "1.2.0-rc.2", hash("sha256","payload"), ""));
$checks["expired_token"]=is_wp_error($v->assert_release_signature(
  hash_hmac("sha256", "1.2.0-rc.2|".hash("sha256","payload")."|2000-01-01T00:00:00Z", "site-secret"),
  "site-secret",
  "1.2.0-rc.2",
  hash("sha256","payload"),
  "2000-01-01T00:00:00Z"
));
@unlink($tmp);
foreach($checks as $k=>$ok) echo $k, "=", $ok?"block":"FAIL", "\n";
foreach($checks as $k=>$ok) { if (!$ok) exit(1);} 
' --path="$WP" --allow-root

echo "=== LOCKED still can update_check ==="
wp eval '
$cm=SEOAuto\SEOHelper\Plugin::instance()->connection();
$cm->update_option("status", SEOAuto\SEOHelper\Connection\Connection_Manager::STATUS_LOCKED);
$u=SEOAuto\SEOHelper\Plugin::instance()->updater();
$r=$u->force_check();
echo is_wp_error($r) ? ("err ".$r->get_error_code()) : ("locked_check_ok available=".( !empty($r->update_available)?"1":"0")), "\n";
$cm->update_option("status", SEOAuto\SEOHelper\Connection\Connection_Manager::STATUS_CONNECTED);
' --path="$WP" --allow-root

echo "=== missing object on release create ==="
SHA=$(python3 -c 'import hashlib;print(hashlib.sha256(b"x").hexdigest())')
CODE=$(curl -sS -o /tmp/bad_release.json -w "%{http_code}" -X POST "${BASE}/api/wordpress-plugin/releases" \
  -H "Content-Type: application/json" -H "X-SEOAuto-CI-Token: $TOKEN" \
  -d "{\"version\":\"${DUMMY_VER}\",\"channel\":\"beta\",\"storage_key\":\"missing/nope.zip\",\"sha256\":\"${SHA}\",\"signature\":\"x\",\"verify_object_exists\":true}")
echo "missing_object_http=$CODE"
python3 - <<'PY'
code=int(open("/tmp/bad_release.code","w").write("") or 0)
PY
test "$CODE" -ge 400
echo "missing_object_blocked_ok"

echo "=== withdraw dummy only (not production RC) ==="
# Create a draft dummy then withdraw if create succeeded without object verify; otherwise skip
curl -sS -X POST "${BASE}/api/wordpress-plugin/releases/${DUMMY_VER}/withdraw" \
  -H "Content-Type: application/json" -H "X-SEOAuto-CI-Token: $TOKEN" \
  -d '{"channel":"beta","reason":"neg e2e cleanup"}' >/tmp/withdraw_dummy.json || true
echo "dummy_withdraw_attempted"

echo "=== confirm published RC still available for update check ==="
wp eval '
$cm=SEOAuto\SEOHelper\Plugin::instance()->connection();
$cm->update_option("update_channel","beta");
$u=SEOAuto\SEOHelper\Plugin::instance()->updater();
$r=$u->force_check();
if (is_wp_error($r)) { echo "err ", $r->get_error_code(), "\n"; exit(1);} 
echo "rc_check version=", $r->version, " available=", !empty($r->update_available)?"1":"0", "\n";
' --path="$WP" --allow-root

echo "=== publish trial post + pairing intact ==="
wp post create --post_title="RC2 neg $(date -Is)" --post_status=publish --porcelain --path="$WP" --allow-root >/dev/null
wp eval '
$c=SEOAuto\SEOHelper\Plugin::instance()->connection();
echo "paired=", $c->has_credentials()?"yes":"no", " status=", $c->option("status",""), PHP_EOL;
if (!$c->has_credentials()) exit(1);
' --path="$WP" --allow-root

echo NEGATIVE_RC_OK
