#!/bin/bash
set -euo pipefail
WP1=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
WP2=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper2-staging.siteauto.vn

cat > /tmp/seohelper-rc3-refresh.php <<'PHP'
<?php
$r = \SEOAuto\SEOHelper\Plugin::instance()->entitlement()->refresh_check('rc3_test');
echo 'allowed=' . (!empty($r['allowed']) ? '1' : '0') . PHP_EOL;
echo 'locked=' . (!empty($r['locked']) ? '1' : '0') . PHP_EOL;
echo 'content_ops=' . (\SEOAuto\SEOHelper\Plugin::instance()->entitlement()->has_feature('content_ops') ? 'yes' : 'no') . PHP_EOL;
echo 'version=' . SEOAUTO_HELPER_VERSION . PHP_EOL;
PHP

echo "======== REFRESH ENTITLEMENT ========"
for WP in "$WP1" "$WP2"; do
  echo "---- $WP ----"
  wp eval-file /tmp/seohelper-rc3-refresh.php --path="$WP" --allow-root
done

echo "======== CONTENT OPS E2E ========"
bash /tmp/run-phase2-content-ops-e2e.sh "$WP1"
bash /tmp/run-phase2-content-ops-e2e.sh "$WP2"

echo "======== EXT E2E SITE1 ========"
bash /tmp/run-phase2-content-ops-ext-e2e.sh "$WP1"

echo "======== NEGATIVE ========"
sed -i 's/1.2.0-rc.2/1.2.0-rc.3/g' /tmp/run-negative-rc2.sh || true
bash /tmp/run-negative-rc2.sh

echo "======== PLUGINS ========"
wp plugin list --path="$WP1" --allow-root --fields=name,status,version | grep -E 'seoauto|rank-math|wordfence'
wp plugin list --path="$WP2" --allow-root --fields=name,status,version | grep -E 'seoauto|wordpress-seo|rank-math'

echo "======== UI MARKERS ========"
grep -c dashboard-simple "$WP1/wp-content/plugins/seoauto-seo-helper/includes/Admin/Overview_Page.php"
grep -n 'Sửa SEO\|content_ops\|has_feature' "$WP1/wp-content/plugins/seoauto-seo-helper/includes/Admin/Admin_Menu.php" | head -10

echo ALL_RC3_STAGING_DONE
