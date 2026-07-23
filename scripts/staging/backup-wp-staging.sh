#!/usr/bin/env bash
# Backup WP staging + snapshot pre-update metrics. No secrets printed.
set -euo pipefail
WP=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
STAMP=$(date +%Y%m%d_%H%M%S)
BAK_ROOT=/var/www/siteauto_vn_usr/data/backups/seohelper-staging-e2e-${STAMP}
mkdir -p "$BAK_ROOT"

echo "=== PRE SNAPSHOT ==="
wp plugin get seoauto-seo-helper --fields=name,version,status --path="$WP" --allow-root
wp eval '
$p = SEOAuto\SEOHelper\Plugin::instance();
$c = $p->connection();
echo "status=", $c->option("status",""), "\n";
echo "site_id=", $c->option("site_id",""), "\n";
echo "connection_id=", $c->option("connection_id",""), "\n";
echo "api=", $c->api_base(), "\n";
echo "channel=", $c->option("update_channel","stable"), "\n";
echo "db_version=", get_option("seoauto_helper_db_version",""), "\n";
echo "posts=", wp_count_posts("post")->publish, "\n";
echo "media=", wp_count_posts("attachment")->inherit, "\n";
global $wpdb;
$audit = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}seoauto_helper_audit_runs");
echo "audit_runs=", $audit, "\n";
echo "has_updater=", class_exists("SEOAuto\\SEOHelper\\Updater\\Update_Manager") ? "yes":"no", "\n";
' --path="$WP" --allow-root 2>/dev/null || wp eval '
echo "status=", SEOAuto\SEOHelper\Plugin::instance()->connection()->option("status",""), "\n";
echo "site_id=", SEOAuto\SEOHelper\Plugin::instance()->connection()->option("site_id",""), "\n";
echo "api=", SEOAuto\SEOHelper\Plugin::instance()->connection()->api_base(), "\n";
echo "posts=", wp_count_posts("post")->publish, "\n";
echo "media=", wp_count_posts("attachment")->inherit, "\n";
echo "db_version=", get_option("seoauto_helper_db_version",""), "\n";
echo "has_updater=", class_exists("SEOAuto\\SEOHelper\\Updater\\Update_Manager") ? "yes":"no", "\n";
' --path="$WP" --allow-root

# DB dump
DBNAME=$(wp config get DB_NAME --path="$WP" --allow-root)
DBUSER=$(wp config get DB_USER --path="$WP" --allow-root)
DBPASS=$(wp config get DB_PASSWORD --path="$WP" --allow-root)
DBHOST=$(wp config get DB_HOST --path="$WP" --allow-root)
mysqldump -h"$DBHOST" -u"$DBUSER" -p"$DBPASS" "$DBNAME" | gzip > "$BAK_ROOT/db.sql.gz"
# wp-content (exclude cache)
tar -C "$WP" --exclude='wp-content/cache' --exclude='wp-content/uploads/cache' -czf "$BAK_ROOT/wp-content.tar.gz" wp-content
ls -lh "$BAK_ROOT"
echo "BACKUP_OK $BAK_ROOT"
