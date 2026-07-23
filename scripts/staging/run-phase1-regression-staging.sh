#!/usr/bin/env bash
# Phase 1 regression smoke on both staging WP sites (no secrets printed).
set -euo pipefail
WP1=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
WP2=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper2-staging.siteauto.vn
FAILS=0

check_site() {
  local WP="$1" NAME="$2"
  echo "==== $NAME ===="
  local VER ACTIVE STATUS API SITE POSTS MEDIA
  VER=$(wp plugin get seoauto-seo-helper --field=version --path="$WP" --allow-root)
  ACTIVE=$(wp plugin is-active seoauto-seo-helper --path="$WP" --allow-root && echo yes || echo no)
  STATUS=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("status","");' --path="$WP" --allow-root)
  API=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->api_base();' --path="$WP" --allow-root)
  SITE=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("site_id","");' --path="$WP" --allow-root)
  POSTS=$(wp post list --post_type=post --format=count --path="$WP" --allow-root)
  MEDIA=$(wp post list --post_type=attachment --format=count --path="$WP" --allow-root)
  echo "version=$VER active=$ACTIVE status=$STATUS api=$API site_len=${#SITE} posts=$POSTS media=$MEDIA"

  local ok=1
  [[ "$ACTIVE" == "yes" ]] || { echo FAIL_inactive; ok=0; }
  [[ "$STATUS" == "connected" ]] || { echo FAIL_pairing; ok=0; }
  [[ "$API" == *"staging.seoauto.vn"* ]] || { echo FAIL_api; ok=0; }
  [[ -n "$SITE" ]] || { echo FAIL_site_id; ok=0; }

  wp eval '
$u=SEOAuto\SEOHelper\Plugin::instance()->updater();
$r=$u->force_check();
if (is_wp_error($r)) { echo "FAIL update_check ", $r->get_error_code(), "\n"; exit(1); }
echo "update_check_ok newest=", $r->version, "\n";
' --path="$WP" --allow-root || ok=0

  local NEW_ID
  NEW_ID=$(wp post create --post_title="P1 regress $(date -Is)" --post_status=publish --porcelain --path="$WP" --allow-root)
  [[ -n "$NEW_ID" ]] || { echo FAIL_publish; ok=0; }
  echo "publish_ok id=$NEW_ID"

  # LOCKED gate: can_mutate when connected
  wp eval '
$e=SEOAuto\SEOHelper\Plugin::instance()->entitlement();
echo "locked=", $e->is_locked()?"yes":"no", " can_mutate=", $e->can_mutate()?"yes":"no", PHP_EOL;
if ($e->is_locked()) { echo "FAIL unexpectedly locked\n"; exit(1); }
' --path="$WP" --allow-root || ok=0

  local POSTS2
  POSTS2=$(wp post list --post_type=post --format=count --path="$WP" --allow-root)
  [[ "$POSTS2" -ge "$POSTS" ]] || { echo FAIL_posts_decreased; ok=0; }
  echo "posts_preserved $POSTS -> $POSTS2"

  if [[ "$ok" != "1" ]]; then FAILS=$((FAILS+1)); echo "SITE_FAIL $NAME"; else echo "SITE_PASS $NAME"; fi
}

check_site "$WP1" site1
check_site "$WP2" site2

# Bridge artifact presence (immutable check)
if [[ -f /tmp/seohelper-staging-scripts/run-chain-104-105-rc1.sh ]]; then
  echo "bridge_chain_script=present"
else
  echo "bridge_chain_script=missing_warn"
fi

# R2 smoke quick (rotated key only)
if [[ -f /tmp/seohelper-staging-scripts/smoke-r2-staging.sh ]]; then
  # Only rotation check snippet, avoid full restart if possible
  python3 - <<'PY'
from pathlib import Path
import hashlib
kv={}
for ln in Path("/var/www/seoauto_vn_usr/data/www-staging/env.local").read_text().splitlines():
    if "=" in ln and not ln.strip().startswith("#"):
        k,v=ln.split("=",1); kv[k.strip()]=v.strip()
leaked="d360dc3836eb0b45ba5661c49860a4f7"
cur=kv.get("R2_ACCESS_KEY_ID","")
print("r2_rotated=", "YES" if cur and cur!=leaked else "NO")
print("r2_ak_sha12=", hashlib.sha256(cur.encode()).hexdigest()[:12] if cur else "missing")
PY
fi

systemctl is-active digiseo >/dev/null && echo prod_digiseo=active
systemctl is-active digiseo-staging >/dev/null && echo staging_digiseo=active

if [[ "$FAILS" -eq 0 ]]; then
  echo PHASE1_REGRESSION_PASS
  exit 0
fi
echo PHASE1_REGRESSION_FAIL count=$FAILS
exit 1
