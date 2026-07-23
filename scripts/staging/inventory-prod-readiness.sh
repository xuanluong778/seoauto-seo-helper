#!/bin/bash
set -euo pipefail
PROD_ENV=/var/www/seoauto_vn_usr4940/data/www/seoauto.vn/env.local
STG_ENV=/var/www/seoauto_vn_usr/data/www-staging/env.local
echo "=== env key names ==="
for F in "$PROD_ENV" "$STG_ENV"; do
  echo "-- $F --"
  if [ -f "$F" ]; then
    grep -E '^(R2_|WP_PLUGIN_|SEOAUTO_ENV|APP_BASE)' "$F" | cut -d= -f1 | sort -u
  else
    echo MISSING_FILE
  fi
done
echo "=== buckets present ==="
if grep -q '^R2_BUCKET=' "$STG_ENV" 2>/dev/null; then echo staging_R2_BUCKET=present; else echo staging_R2_BUCKET=absent; fi
if grep -q '^R2_BUCKET=' "$PROD_ENV" 2>/dev/null; then echo prod_R2_BUCKET=present; else echo prod_R2_BUCKET=absent; fi
for K in WP_PLUGIN_CI_RELEASE_TOKEN WP_PLUGIN_RELEASE_SIGNING_KEY R2_ACCESS_KEY_ID; do
  if grep -q "^${K}=" "$PROD_ENV" 2>/dev/null; then echo "prod_${K}=present"; else echo "prod_${K}=absent"; fi
  if grep -q "^${K}=" "$STG_ENV" 2>/dev/null; then echo "stg_${K}=present"; else echo "stg_${K}=absent"; fi
done
echo "=== content_ops in SaaS ==="
grep -n content_ops /var/www/seoauto_vn_usr/data/www-staging/app/services/plugin_entitlement_service.py 2>/dev/null | head -8 || echo staging_absent
grep -n content_ops /var/www/seoauto_vn_usr4940/data/www/seoauto.vn/app/services/plugin_entitlement_service.py 2>/dev/null | head -8 || echo prod_absent
echo "=== overview UI markers ==="
WP1=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
grep -c dashboard-simple "$WP1/wp-content/plugins/seoauto-seo-helper/includes/Admin/Overview_Page.php" || true
grep -c 'has_feature' "$WP1/wp-content/plugins/seoauto-seo-helper/includes/Admin/Admin_Menu.php" || true
