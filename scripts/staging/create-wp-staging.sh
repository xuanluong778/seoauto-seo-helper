#!/usr/bin/env bash
# Create WordPress staging on seohelper-staging.siteauto.vn (wildcard DNS already resolves).
set -euo pipefail
DOMAIN=seohelper-staging.siteauto.vn
SITES_ROOT=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites
SITE_DIR="$SITES_ROOT/$DOMAIN"
DB_NAME=wp_seohelper_stg
DB_USER=wp_seohelper_stg
DB_PASS=$(openssl rand -hex 12)
ADMIN_PASS=$(openssl rand -hex 8)
IP=116.118.45.72
PHP_SOCK=/var/run/php/php8.3-fpm.sock
PLUGIN_ZIP="${1:-}"

echo "==> Creating $DOMAIN"
mkdir -p "$SITES_ROOT"
if [[ ! -f "$SITE_DIR/wp-load.php" ]]; then
  mkdir -p "$SITE_DIR"
  if [[ ! -f /tmp/wordpress-latest.tar.gz ]]; then
    curl -fsSL https://wordpress.org/latest.tar.gz -o /tmp/wordpress-latest.tar.gz
  fi
  rm -rf /tmp/wordpress
  tar -xzf /tmp/wordpress-latest.tar.gz -C /tmp
  rsync -a /tmp/wordpress/ "$SITE_DIR/"
fi

mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" || true
mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"

if [[ ! -f "$SITE_DIR/wp-config.php" ]]; then
  wp config create --path="$SITE_DIR" --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost=localhost --dbprefix=wps_ --skip-check --allow-root
fi

if ! wp core is-installed --path="$SITE_DIR" --allow-root 2>/dev/null; then
  wp core install --path="$SITE_DIR" --url="https://${DOMAIN}" --title="SEO Helper Staging" \
    --admin_user=stagingadmin --admin_password="$ADMIN_PASS" --admin_email=staging-helper@seoauto.vn --skip-email --allow-root
fi

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

# plugins
if [[ -n "$PLUGIN_ZIP" && -f "$PLUGIN_ZIP" ]]; then
  wp plugin install "$PLUGIN_ZIP" --force --activate --path="$SITE_DIR" --allow-root
fi
wp plugin install seo-by-rank-math --activate --path="$SITE_DIR" --allow-root || true
wp plugin install wordfence --activate --path="$SITE_DIR" --allow-root || true
wp post create --post_title='Staging E2E Post' --post_status=publish --path="$SITE_DIR" --allow-root || true

BACKUP_DIR="/var/backups/seohelper-staging/$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"
wp db export "$BACKUP_DIR/db.sql" --path="$SITE_DIR" --allow-root
tar -C "$SITES_ROOT" -czf "$BACKUP_DIR/wp-content.tgz" "$DOMAIN/wp-content"
echo "$ADMIN_PASS" > "$BACKUP_DIR/admin.pass"
chmod 600 "$BACKUP_DIR/admin.pass"
echo "DB_PASS=$DB_PASS" > "$BACKUP_DIR/db.pass"
chmod 600 "$BACKUP_DIR/db.pass"

chown -R siteauto_vn_usr:siteauto_vn_usr "$SITE_DIR" || true
echo "WP_URL=https://${DOMAIN}"
echo "ADMIN=stagingadmin"
echo "BACKUP=$BACKUP_DIR"
wp plugin list --path="$SITE_DIR" --allow-root || true
echo WP_STAGING_OK
