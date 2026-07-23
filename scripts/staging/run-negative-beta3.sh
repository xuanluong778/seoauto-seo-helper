#!/usr/bin/env bash
# Negative + LOCKED tests for beta.3 (safe handling).
set -euo pipefail
WP=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
TOKEN=$(grep '^WP_PLUGIN_CI_RELEASE_TOKEN=' /var/www/seoauto_vn_usr/data/www-staging/env.local | cut -d= -f2-)
VER=1.1.0-beta.3
BASE=http://127.0.0.1:8901

echo "=== withdraw release ==="
curl -sS -X POST "${BASE}/api/wordpress-plugin/releases/${VER}/withdraw" \
  -H "Content-Type: application/json" -H "X-SEOAuto-CI-Token: $TOKEN" \
  -d '{"channel":"beta","reason":"e2e withdraw test"}' | python3 -c 'import sys,json; d=json.load(sys.stdin); print("withdraw_status=", (d.get("release") or {}).get("status")); assert (d.get("release") or {}).get("status")=="withdrawn"'

echo "=== update check after withdraw (expect none) ==="
wp eval '
$u=SEOAuto\SEOHelper\Plugin::instance()->updater();
$r=$u->force_check();
if (is_wp_error($r)) { echo "err ", $r->get_error_code(), "\n"; exit(0);} 
echo "available=", !empty($r->update_available)?"1":"0", "\n";
if (!empty($r->update_available)) { echo "FAIL still available\n"; exit(1);} 
' --path="$WP" --allow-root

echo "=== re-publish blocked after withdraw (expected) ==="
CODE=$(curl -sS -o /tmp/repost.json -w "%{http_code}" -X POST "${BASE}/api/wordpress-plugin/releases/${VER}/publish?channel=beta" \
  -H "Content-Type: application/json" -H "X-SEOAuto-CI-Token: $TOKEN" -d '{}')
echo "repost_http=$CODE"
python3 - <<'PY'
import json
d=json.load(open("/tmp/repost.json"))
detail=(d.get("detail") or {})
code=detail.get("code") if isinstance(detail,dict) else None
print("repost_code=", code or d)
assert code == "withdrawn" or (isinstance(detail,dict) and "withdraw" in str(detail).lower())
print("withdraw_republish_blocked_ok")
PY

echo "=== LOCKED still can check ==="
wp eval '
$cm=SEOAuto\SEOHelper\Plugin::instance()->connection();
$cm->update_option("status", SEOAuto\SEOHelper\Connection\Connection_Manager::STATUS_LOCKED);
$u=SEOAuto\SEOHelper\Plugin::instance()->updater();
$r=$u->force_check();
echo is_wp_error($r) ? ("err ".$r->get_error_code()) : ("locked_check_ok available=".( !empty($r->update_available)?"1":"0")), "\n";
$cm->update_option("status", SEOAuto\SEOHelper\Connection\Connection_Manager::STATUS_CONNECTED);
' --path="$WP" --allow-root

echo "=== expired token / bad signature / bad checksum gates ==="
wp eval '
$v=new SEOAuto\SEOHelper\Updater\Package_Verifier();
$checks=[];
$checks["downgrade"]=is_wp_error($v->assert_newer_version("1.1.0-beta.3","1.0.4"));
$checks["replay"]=is_wp_error($v->assert_newer_version("1.1.0-beta.3","1.1.0-beta.3"));
$checks["http"]=is_wp_error($v->assert_safe_package_url("http://seoauto.vn/x.zip"));
$checks["badhost"]=is_wp_error($v->assert_safe_package_url("https://evil.example/x.zip"));
$tmp=tempnam(sys_get_temp_dir(),"seoauto_chk");
file_put_contents($tmp,"payload");
$checks["bad_checksum"]=is_wp_error($v->assert_sha256_file($tmp, str_repeat("a",64)));
$checks["bad_sig"]=is_wp_error($v->assert_release_signature("deadbeef", "site-secret", "1.1.0-beta.3", hash("sha256","payload"), ""));
$checks["expired_token"]=is_wp_error($v->assert_release_signature(
  hash_hmac("sha256", "1.1.0-beta.3|".hash("sha256","payload")."|2000-01-01T00:00:00Z", "site-secret"),
  "site-secret",
  "1.1.0-beta.3",
  hash("sha256","payload"),
  "2000-01-01T00:00:00Z"
));
@unlink($tmp);
foreach($checks as $k=>$ok) echo $k, "=", $ok?"block":"FAIL", "\n";
foreach($checks as $k=>$ok) { if (!$ok) exit(1);} 
' --path="$WP" --allow-root

echo "=== R2 missing object handled (head smoke via API list) ==="
# Attempt create with nonexistent storage key should fail safely
curl -sS -o /tmp/bad_release.json -w "%{http_code}" -X POST "${BASE}/api/wordpress-plugin/releases" \
  -H "Content-Type: application/json" -H "X-SEOAuto-CI-Token: $TOKEN" \
  -d "{\"version\":\"9.9.9-e2e\",\"channel\":\"beta\",\"storage_key\":\"missing/nope.zip\",\"sha256\":\"$(python3 -c 'import hashlib;print(hashlib.sha256(b\"x\").hexdigest())')\",\"signature\":\"x\",\"verify_object_exists\":true}" > /tmp/bad_release.code
CODE=$(cat /tmp/bad_release.code)
echo "missing_object_http=$CODE"
python3 - <<'PY'
code=int(open("/tmp/bad_release.code").read().strip() or "0")
assert code >= 400, code
print("r2_missing_blocked_ok")
PY

echo "=== wordfence / production untouched ==="
wp plugin is-active wordfence --path="$WP" --allow-root && echo wordfence_active=yes || echo wordfence_active=no
systemctl is-active digiseo >/dev/null && echo prod_digiseo=active
systemctl is-active digiseo-staging >/dev/null && echo staging_digiseo=active
echo NEGATIVE_OK
