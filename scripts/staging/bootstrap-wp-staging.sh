#!/usr/bin/env bash
# Create isolated WordPress staging site for SEO Helper E2E (siteauto subdomain).
# Does not touch production seoauto.vn.
set -euo pipefail

DOMAIN="${DOMAIN:-seohelper-staging.siteauto.vn}"
SITES_ROOT="${SITES_ROOT:-/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites}"
SITE_DIR="${SITES_ROOT}/${DOMAIN}"
DB_NAME="${DB_NAME:-wp_seohelper_staging}"
DB_USER="${DB_USER:-wp_seohelper_st}"
PHP_SOCK="${PHP_SOCK:-/var/run/php/php8.3-fpm.sock}"
IP="${IP:-116.118.45.72}"
PLUGIN_ZIP_V104="${PLUGIN_ZIP_V104:-}"

echo "==> WP staging: https://${DOMAIN}"

DB_PASS="${DB_PASS:-$(openssl rand -hex 12)}"
mkdir -p "$SITES_ROOT"

if [[ ! -f "$SITE_DIR/wp-config.php" ]]; then
  mkdir -p "$SITE_DIR"
  if [[ ! -f /tmp/wordpress-latest.tar.gz ]]; then
    curl -fsSL https://wordpress.org/latest.tar.gz -o /tmp/wordpress-latest.tar.gz
  fi
  tar -xzf /tmp/wordpress-latest.tar.gz -C /tmp
  rsync -a /tmp/wordpress/ "$SITE_DIR/"
fi

sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${DB_USER}') THEN
    CREATE ROLE ${DB_USER} LOGIN PASSWORD '${DB_PASS}';
  END IF;
END
\$\$;
SQL

# Prefer MariaDB/MySQL for WP if available
if command -v mysql >/dev/null 2>&1; then
  mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
  mysql -e "GRANT ALL ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
  DB_HOST=localhost
  TABLE_PREFIX=wp_
else
  echo "MySQL not found — install MariaDB or configure existing DB manually" >&2
  exit 1
fi

if [[ ! -f "$SITE_DIR/wp-config.php" ]]; then
  wp config create --path="$SITE_DIR" --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --dbprefix="$TABLE_PREFIX" --skip-check --allow-root
fi

ADMIN_PASS="${WP_ADMIN_PASS:-$(openssl rand -hex 8)}"
if ! wp core is-installed --path="$SITE_DIR" --allow-root 2>/dev/null; then
  wp core install --path="$SITE_DIR" --url="https://${DOMAIN}" --title="SEO Helper Staging" \
    --admin_user=stagingadmin --admin_password="$ADMIN_PASS" --admin_email=staging@seoauto.vn --skip-email --allow-root
fi

# nginx vhost
cat > "/etc/nginx/conf.d/webauto-wp-vhosts/${DOMAIN}.conf" <<EOF
server {
    listen ${IP}:80;
    server_name ${DOMAIN};
    return 301 https://\$host\$request_uri;
}
server {
    listen ${IP}:443 ssl;
    server_name ${DOMAIN};
    ssl_certificate /etc/letsencrypt/live/seo.siteauto.vn/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/seo.siteauto.vn/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    client_max_body_size 128M;
    root ${SITE_DIR};
    index index.php;
    location / { try_files \$uri \$uri/ /index.php?\$args; }
    location ~ \\.php\$ {
        include /etc/nginx/fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:${PHP_SOCK};
    }
    location ~* /wp-content/uploads/.*\\.ph(p[3457]?|t|tml)\$ { deny all; }
}
EOF

nginx -t && systemctl reload nginx

# Install plugin v1.0.4 if ZIP provided
if [[ -n "$PLUGIN_ZIP_V104" && -f "$PLUGIN_ZIP_V104" ]]; then
  wp plugin install "$PLUGIN_ZIP_V104" --force --activate --path="$SITE_DIR" --allow-root
fi

# Try install SEO plugins + wordfence from wordpress.org (non-fatal)
wp plugin install seo-by-rank-math --activate --path="$SITE_DIR" --allow-root || true
wp plugin install wordfence --activate --path="$SITE_DIR" --allow-root || true

# Sample content
wp post create --post_title='Staging E2E Post' --post_status=publish --path="$SITE_DIR" --allow-root || true

# Backup
BACKUP_DIR="/var/backups/seohelper-staging/$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"
wp db export "$BACKUP_DIR/db.sql" --path="$SITE_DIR" --allow-root
tar -C "$(dirname "$SITE_DIR")" -czf "$BACKUP_DIR/wp-content.tgz" "$(basename "$SITE_DIR")/wp-content"
echo "$ADMIN_PASS" > "$BACKUP_DIR/admin.pass"
chmod 600 "$BACKUP_DIR/admin.pass"

chown -R siteauto_vn_usr:siteauto_vn_usr "$SITE_DIR" 2>/dev/null || chown -R www-data:www-data "$SITE_DIR" || true

echo "==> WP staging ready: https://${DOMAIN}"
echo "Admin user: stagingadmin  pass file: $BACKUP_DIR/admin.pass"
echo "Backup: $BACKUP_DIR"
echo "Add DNS A: ${DOMAIN} -> ${IP} then certbot --nginx -d ${DOMAIN}"
