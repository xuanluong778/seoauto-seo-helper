#!/bin/bash
set -euo pipefail
WP1=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
WP2=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper2-staging.siteauto.vn
SCRIPT_DIR=/tmp/seohelper-ui-smoke
mkdir -p "$SCRIPT_DIR"

cat > "$SCRIPT_DIR/status.php" <<'PHP'
<?php
echo SEOAUTO_HELPER_VERSION . PHP_EOL;
$plugin = \SEOAuto\SEOHelper\Plugin::instance();
$conn = $plugin->connection();
$ent = $plugin->entitlement();
echo 'paired=' . ($conn->has_credentials() ? 'yes' : 'no') . PHP_EOL;
echo 'connected=' . ($conn->is_connected() ? 'yes' : 'no') . PHP_EOL;
echo 'locked=' . ($ent->is_locked() ? 'yes' : 'no') . PHP_EOL;
echo 'content_ops=' . ($ent->has_feature('content_ops') ? 'yes' : 'no') . PHP_EOL;
echo 'posts_publish=' . (int) wp_count_posts('post')->publish . PHP_EOL;
$active = get_option('active_plugins', array());
echo 'helper_active=' . (in_array('seoauto-seo-helper/seoauto-seo-helper.php', $active, true) ? 'yes' : 'no') . PHP_EOL;
PHP

cat > "$SCRIPT_DIR/overview.php" <<'PHP'
<?php
wp_set_current_user(1);
ob_start();
(new \SEOAuto\SEOHelper\Admin\Overview_Page(
  \SEOAuto\SEOHelper\Plugin::instance()->connection(),
  \SEOAuto\SEOHelper\Plugin::instance()->entitlement(),
  \SEOAuto\SEOHelper\Plugin::instance()->updater()
))->render();
$html = ob_get_clean();
file_put_contents('/tmp/seohelper-ui-after-overview.html', $html);
foreach (array(
  'Kết nối SEOAuto',
  'Phiên bản plugin',
  'Trạng thái cập nhật',
  'Kiểm tra cập nhật',
  'connection_id',
  'site_secret',
  'stat-grid',
  'feature-pill',
  'dashboard-simple',
) as $needle) {
  echo $needle . '=' . (str_contains($html, $needle) ? 'YES' : 'no') . PHP_EOL;
}
echo 'bytes=' . strlen($html) . PHP_EOL;
PHP

cat > "$SCRIPT_DIR/update_check.php" <<'PHP'
<?php
$mgr = \SEOAuto\SEOHelper\Plugin::instance()->updater();
$res = $mgr->force_check();
if (is_wp_error($res)) {
  echo 'update_check=ERR:' . $res->get_error_code() . PHP_EOL;
  exit(0);
}
echo 'update_check=ok available=' . ($res->update_available ? 'yes' : 'no') . ' version=' . $res->version . PHP_EOL;
PHP

echo "=== UI markers site1 ==="
grep -n "dashboard-simple\|render_upgrade_form\|has_feature" \
  "$WP1/wp-content/plugins/seoauto-seo-helper/includes/Admin/Overview_Page.php" \
  "$WP1/wp-content/plugins/seoauto-seo-helper/includes/Updater/Update_Admin.php" \
  "$WP1/wp-content/plugins/seoauto-seo-helper/includes/Admin/Admin_Menu.php" | head -30

echo "=== dual smoke ==="
for WP in "$WP1" "$WP2"; do
  echo "---- $WP ----"
  wp plugin list --path="$WP" --allow-root --fields=name,status,version | grep -iE 'seoauto|rank-math|wordpress-seo|wordfence' || true
  wp eval-file "$SCRIPT_DIR/status.php" --path="$WP" --allow-root
done

echo "=== overview HTML assertions site1 ==="
wp eval-file "$SCRIPT_DIR/overview.php" --path="$WP1" --allow-root

echo "=== update check site1 ==="
wp eval-file "$SCRIPT_DIR/update_check.php" --path="$WP1" --allow-root
echo "=== update check site2 ==="
wp eval-file "$SCRIPT_DIR/update_check.php" --path="$WP2" --allow-root

cat > "$SCRIPT_DIR/content_ops.php" <<'PHP'
<?php
$ops = \SEOAuto\SEOHelper\Plugin::instance()->content_ops();
$batches = $ops->recent_batches(3);
echo 'batches=' . count($batches) . PHP_EOL;
foreach ($batches as $b) {
  echo 'batch id=' . (int) $b['id'] . ' status=' . $b['status'] . PHP_EOL;
}
PHP

echo "=== content ops recent batches site1 ==="
wp eval-file "$SCRIPT_DIR/content_ops.php" --path="$WP1" --allow-root
echo "=== content ops recent batches site2 ==="
wp eval-file "$SCRIPT_DIR/content_ops.php" --path="$WP2" --allow-root

echo ALL_SMOKE_DONE
