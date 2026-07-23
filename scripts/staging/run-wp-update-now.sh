#!/usr/bin/env bash
set -euo pipefail
WP=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
START=$(date -Is)
echo "START=$START"
# Capture pre-state
PRE_SITE=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("site_id","");' --path="$WP" --allow-root)
PRE_CONN=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("connection_id","");' --path="$WP" --allow-root)
PRE_STATUS=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("status","");' --path="$WP" --allow-root)
PRE_POSTS=$(wp post list --post_type=post --format=count --path="$WP" --allow-root)
echo "PRE site=$PRE_SITE conn=$PRE_CONN status=$PRE_STATUS posts=$PRE_POSTS ver=$(wp plugin get seoauto-seo-helper --field=version --path=$WP --allow-root)"

# Perform update (simulates Update now)
set +e
OUT=$(wp plugin update seoauto-seo-helper --path="$WP" --allow-root 2>&1)
RC=$?
set -e
echo "$OUT"
echo "UPDATE_RC=$RC"
END=$(date -Is)
echo "END=$END"

POST_VER=$(wp plugin get seoauto-seo-helper --field=version --path="$WP" --allow-root)
POST_ACTIVE=$(wp plugin is-active seoauto-seo-helper --path="$WP" --allow-root && echo yes || echo no)
POST_SITE=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("site_id","");' --path="$WP" --allow-root)
POST_CONN=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("connection_id","");' --path="$WP" --allow-root)
POST_STATUS=$(wp eval 'echo SEOAuto\SEOHelper\Plugin::instance()->connection()->option("status","");' --path="$WP" --allow-root)
POST_POSTS=$(wp post list --post_type=post --format=count --path="$WP" --allow-root)
HAS_UPD=$(wp eval 'echo class_exists("SEOAuto\\SEOHelper\\Updater\\Update_Manager")?"yes":"no";' --path="$WP" --allow-root)

echo "POST ver=$POST_VER active=$POST_ACTIVE site=$POST_SITE conn=$POST_CONN status=$POST_STATUS posts=$POST_POSTS updater=$HAS_UPD"
# migration smoke
wp eval 'SEOAuto\SEOHelper\Post\Schema::maybe_upgrade(); echo "db_ok\n";' --path="$WP" --allow-root || true
# frontend smoke
curl -sk -o /dev/null -w "front %{http_code}\n" "https://seohelper-staging.siteauto.vn/"
# wordfence still active?
wp plugin list --path="$WP" --allow-root | grep -E 'seoauto|wordfence|rank-math'
echo E2E_UPDATE_DONE
