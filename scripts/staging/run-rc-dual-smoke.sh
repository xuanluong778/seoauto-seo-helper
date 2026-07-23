#!/usr/bin/env bash
# RC dual-site smoke: update check, pairing, publish post, data preserve, Wordfence.
# Never prints secrets. Staging only.
set -euo pipefail

WP1=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
WP2=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper2-staging.siteauto.vn
EXPECT=1.1.0-rc.1
FAILS=0

check_site() {
  local WP="$1" NAME="$2" URL="$3"
  echo "==== $NAME ($URL) ===="
  local VER STATUS SITE API POSTS MEDIA WF ACTIVE DB
  VER=$(wp plugin get seoauto-seo-helper --field=version --path="$WP" --allow-root)
  ACTIVE=$(wp plugin is-active seoauto-seo-helper --path="$WP" --allow-root && echo yes || echo no)
  STATUS=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("status","");' --path="$WP" --allow-root)
  SITE=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("site_id","");' --path="$WP" --allow-root)
  API=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->api_base();' --path="$WP" --allow-root)
  DB=$(wp eval 'echo get_option("seoauto_helper_db_version","");' --path="$WP" --allow-root)
  POSTS=$(wp post list --post_type=post --format=count --path="$WP" --allow-root)
  MEDIA=$(wp post list --post_type=attachment --format=count --path="$WP" --allow-root)
  WF=$(wp plugin is-active wordfence --path="$WP" --allow-root && echo yes || echo no)
  echo "version=$VER expect=$EXPECT active=$ACTIVE status=$STATUS api=$API site=$SITE db=$DB posts=$POSTS media=$MEDIA wordfence=$WF"

  local ok=1
  [[ "$VER" == "$EXPECT" ]] || { echo "FAIL version"; ok=0; }
  [[ "$ACTIVE" == "yes" ]] || { echo "FAIL inactive"; ok=0; }
  [[ "$STATUS" == "connected" ]] || { echo "FAIL pairing status=$STATUS"; ok=0; }
  [[ "$API" == *"staging.seoauto.vn"* ]] || { echo "FAIL api_base=$API"; ok=0; }
  [[ -n "$SITE" ]] || { echo "FAIL empty site_id"; ok=0; }
  [[ "$WF" == "yes" || "$NAME" == "site2" ]] || echo "WARN wordfence inactive on $NAME"

  # Update check (should not error; may or may not have newer)
  wp eval '
$u=SEOAuto\SEOHelper\Plugin::instance()->updater();
$r=$u->force_check();
if (is_wp_error($r)) { echo "FAIL update_check ", $r->get_error_code(), " ", $r->get_error_message(), "\n"; exit(1); }
echo "update_check_ok available=", !empty($r->update_available)?"1":"0", " newest=", $r->version, "\n";
' --path="$WP" --allow-root || ok=0

  # Trial publish post
  local NEW_ID
  NEW_ID=$(wp post create --post_title="RC smoke $(date -Is)" --post_status=publish --porcelain --path="$WP" --allow-root)
  [[ -n "$NEW_ID" ]] || { echo "FAIL create post"; ok=0; }
  local POSTS2
  POSTS2=$(wp post list --post_type=post --format=count --path="$WP" --allow-root)
  echo "publish_trial id=$NEW_ID posts_before=$POSTS posts_after=$POSTS2"
  [[ "$POSTS2" -ge "$POSTS" ]] || { echo "FAIL posts decreased"; ok=0; }

  # Frontend
  local CODE
  CODE=$(curl -sk -o /dev/null -w "%{http_code}" "$URL/")
  echo "front_http=$CODE"
  [[ "$CODE" == "200" || "$CODE" == "301" || "$CODE" == "302" ]] || { echo "FAIL frontend $CODE"; ok=0; }

  # Plugin classes smoke
  wp eval '
echo "has_updater=", class_exists("SEOAuto\\SEOHelper\\Updater\\Update_Manager")?"yes":"no", "\n";
echo "has_verifier=", class_exists("SEOAuto\\SEOHelper\\Updater\\Package_Verifier")?"yes":"no", "\n";
SEOAuto\SEOHelper\Post\Schema::maybe_upgrade();
echo "migration_ok\n";
' --path="$WP" --allow-root || ok=0

  if [[ "$ok" -eq 1 ]]; then
    echo "RESULT_$NAME=PASS"
  else
    echo "RESULT_$NAME=FAIL"
    FAILS=$((FAILS+1))
  fi
}

check_site "$WP1" site1 https://wp-staging.seoauto.vn
check_site "$WP2" site2 https://seohelper2-staging.siteauto.vn

# Production untouched
systemctl is-active digiseo >/dev/null && echo prod_digiseo=active
systemctl is-active digiseo-staging >/dev/null && echo staging_digiseo=active

if [[ "$FAILS" -eq 0 ]]; then
  echo RC_DUAL_SMOKE_PASS
  exit 0
fi
echo RC_DUAL_SMOKE_FAIL count=$FAILS
exit 1
