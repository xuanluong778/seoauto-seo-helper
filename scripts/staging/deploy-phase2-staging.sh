#!/usr/bin/env bash
# Deploy Phase 2 1.2.0-dev plugin to WP staging site1 only (no production).
set -euo pipefail
SITE="${1:-/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn}"
SRC="${2:-/tmp/seohelper-phase2-plugin}"
PLUGIN_DIR="$SITE/wp-content/plugins/seoauto-seo-helper"

echo "Deploying to $PLUGIN_DIR from $SRC"
test -f "$SRC/seoauto-seo-helper.php"
test -d "$SRC/includes/ContentOps"

# Preserve nothing special — rsync code only
rsync -a --delete \
  --exclude '.git' \
  --exclude 'artifacts' \
  --exclude 'tests' \
  --exclude 'scripts' \
  --exclude 'docs' \
  --exclude '*.md' \
  "$SRC/" "$PLUGIN_DIR/"

# Ensure tables upgrade on next request
wp --path="$SITE" eval 'echo SEOAUTO_HELPER_VERSION . PHP_EOL; \SEOAuto\SEOHelper\Post\Schema::maybe_upgrade(); echo "db=" . (int) get_option("seoauto_helper_db_version") . PHP_EOL;' 2>/dev/null \
  || php -r "
  define('ABSPATH', '$SITE/');
  require '$SITE/wp-load.php';
  echo SEOAUTO_HELPER_VERSION, PHP_EOL;
  \SEOAuto\SEOHelper\Post\Schema::maybe_upgrade();
  echo 'db=', (int) get_option('seoauto_helper_db_version'), PHP_EOL;
"

echo "PHASE2_DEPLOY_OK"
