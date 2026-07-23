#!/usr/bin/env bash
# Second WP staging site for RC validation (isolated DB). Does not touch production digiseo.
set -euo pipefail
DOMAIN="${DOMAIN:-seohelper2-staging.siteauto.vn}"
SITES_ROOT=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites
SITE_DIR="$SITES_ROOT/$DOMAIN"
DB_NAME=wp_seohelper2_stg
DB_USER=wp_seohelper2_stg
DB_PASS=$(openssl rand -hex 12)
ADMIN_PASS=$(openssl rand -hex 8)
IP=116.118.45.72
PHP_SOCK=/var/run/php/php8.3-fpm.sock
PLUGIN_ZIP="${1:-/tmp/seoauto-seo-helper-1.0.5-bridge.zip}"

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
  wp config create --path="$SITE_DIR" --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost=localhost --dbprefix=wps2_ --skip-check --allow-root
fi

if ! wp core is-installed --path="$SITE_DIR" --allow-root 2>/dev/null; then
  wp core install --path="$SITE_DIR" --url="https://${DOMAIN}" --title="SEO Helper Staging 2" \
    --admin_user=stagingadmin --admin_password="$ADMIN_PASS" --admin_email=staging-helper2@seoauto.vn --skip-email --allow-root
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
}
EOF
nginx -t && systemctl reload nginx

if [[ -f "$PLUGIN_ZIP" ]]; then
  wp plugin install "$PLUGIN_ZIP" --force --activate --path="$SITE_DIR" --allow-root
fi
wp plugin install seo-by-rank-math --activate --path="$SITE_DIR" --allow-root || true
wp post create --post_title='Staging2 E2E Post' --post_status=publish --path="$SITE_DIR" --allow-root || true
echo "SITE2_OK $DOMAIN"
wp plugin get seoauto-seo-helper --fields=version,status --path="$SITE_DIR" --allow-root
