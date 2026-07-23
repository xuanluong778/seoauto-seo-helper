#!/usr/bin/env bash
set -euo pipefail
IP=116.118.45.72
API_DOMAIN=staging.seoauto.vn
WP_DOMAIN=wp-staging.seoauto.vn
WP_ROOT=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
STAGING_ROOT=/var/www/seoauto_vn_usr/data/www-staging
PHP_SOCK=/var/run/php/php8.3-fpm.sock
WEBROOT=/var/www/letsencrypt-www
mkdir -p "$WEBROOT/.well-known/acme-challenge"
chmod -R a+rX "$WEBROOT"

echo "==> DNS"
dig +short A "$API_DOMAIN" @8.8.8.8
dig +short A "$WP_DOMAIN" @8.8.8.8
systemctl is-active digiseo.service >/dev/null

# HTTP ACME + redirect (keep this file; do not delete after write)
cat > "/etc/nginx/conf.d/${API_DOMAIN}.conf" <<EOF
upstream digiseo_staging {
    server 127.0.0.1:8901;
    keepalive 8;
}
server {
    listen ${IP}:80;
    server_name ${API_DOMAIN};
    location ^~ /.well-known/acme-challenge/ {
        root ${WEBROOT};
        default_type text/plain;
        allow all;
    }
    location / { return 301 https://\$host\$request_uri; }
}
EOF

cat > "/etc/nginx/conf.d/webauto-wp-vhosts/${WP_DOMAIN}.conf" <<EOF
server {
    listen ${IP}:80;
    server_name ${WP_DOMAIN};
    location ^~ /.well-known/acme-challenge/ {
        root ${WEBROOT};
        default_type text/plain;
        allow all;
    }
    location / { return 301 https://\$host\$request_uri; }
}
EOF

nginx -t && systemctl reload nginx
echo ok-api > "${WEBROOT}/.well-known/acme-challenge/ping-api"
echo ok-wp > "${WEBROOT}/.well-known/acme-challenge/ping-wp"
echo -n "api challenge: "; curl -fsS "http://${API_DOMAIN}/.well-known/acme-challenge/ping-api"; echo
echo -n "wp challenge: "; curl -fsS "http://${WP_DOMAIN}/.well-known/acme-challenge/ping-wp"; echo

certbot certonly --webroot -w "$WEBROOT" -d "$API_DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email
certbot certonly --webroot -w "$WEBROOT" -d "$WP_DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email

# Full HTTPS configs
cat > "/etc/nginx/conf.d/${API_DOMAIN}.conf" <<EOF
upstream digiseo_staging {
    server 127.0.0.1:8901;
    keepalive 8;
}
server {
    listen ${IP}:80;
    server_name ${API_DOMAIN};
    location ^~ /.well-known/acme-challenge/ {
        root ${WEBROOT};
        default_type text/plain;
        allow all;
    }
    location / { return 301 https://\$host\$request_uri; }
}
server {
    listen ${IP}:443 ssl;
    http2 on;
    server_name ${API_DOMAIN};
    ssl_certificate /etc/letsencrypt/live/${API_DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${API_DOMAIN}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    client_max_body_size 64M;
    location / {
        proxy_pass http://digiseo_staging;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_read_timeout 120;
    }
}
EOF

cat > "/etc/nginx/conf.d/webauto-wp-vhosts/${WP_DOMAIN}.conf" <<EOF
server {
    listen ${IP}:80;
    server_name ${WP_DOMAIN};
    location ^~ /.well-known/acme-challenge/ {
        root ${WEBROOT};
        default_type text/plain;
        allow all;
    }
    location / { return 301 https://\$host\$request_uri; }
}
server {
    listen ${IP}:443 ssl;
    http2 on;
    server_name ${WP_DOMAIN};
    ssl_certificate /etc/letsencrypt/live/${WP_DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${WP_DOMAIN}/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    client_max_body_size 128M;
    root ${WP_ROOT};
    index index.php;
    access_log /var/log/nginx/wp-staging-seoauto-vn.access.log;
    error_log /var/log/nginx/wp-staging-seoauto-vn.error.log;
    location ~* /wp-content/uploads/.*\\.ph(p[3457]?|t|tml)\$ { deny all; }
    location / { try_files \$uri \$uri/ /index.php?\$args; }
    location ~ \\.php\$ {
        include /etc/nginx/fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:${PHP_SOCK};
    }
    location ~ /\\.ht { deny all; }
}
EOF

nginx -t && systemctl reload nginx

python3 - <<PY
from pathlib import Path
p = Path("${STAGING_ROOT}/env.local")
lines=[]
for line in p.read_text().splitlines():
    if line.startswith("APP_BASE_URL=") or line.startswith("PUBLIC_BASE_URL="):
        key=line.split("=",1)[0]
        lines.append(f"{key}=https://${API_DOMAIN}")
    else:
        lines.append(line)
p.write_text("\n".join(lines)+"\n")
print("env ok")
PY
if [[ -f ${STAGING_ROOT}/data/staging_ci_secrets.env ]]; then
  sed -i "s#^SEOAUTO_API_BASE=.*#SEOAUTO_API_BASE=https://${API_DOMAIN}#" ${STAGING_ROOT}/data/staging_ci_secrets.env || true
fi
chmod 600 ${STAGING_ROOT}/env.local
chown seoauto_vn_usr:seoauto_vn_usr ${STAGING_ROOT}/env.local
systemctl restart digiseo-staging.service
sleep 12

wp option update home "https://${WP_DOMAIN}" --path="$WP_ROOT" --allow-root
wp option update siteurl "https://${WP_DOMAIN}" --path="$WP_ROOT" --allow-root
wp search-replace "https://seohelper-staging.siteauto.vn" "https://${WP_DOMAIN}" --all-tables --path="$WP_ROOT" --allow-root || true
wp search-replace "http://seohelper-staging.siteauto.vn" "https://${WP_DOMAIN}" --all-tables --path="$WP_ROOT" --allow-root || true
wp search-replace "https://seoauto-api-staging.siteauto.vn" "https://${API_DOMAIN}" --all-tables --path="$WP_ROOT" --allow-root || true
wp eval "
\$cm = SEOAuto\\SEOHelper\\Plugin::instance()->connection();
\$cm->update_option('api_base', 'https://${API_DOMAIN}');
echo 'api_base=', \$cm->api_base(), PHP_EOL;
echo 'status=', \$cm->option('status',''), PHP_EOL;
echo 'site_id=', \$cm->option('site_id',''), PHP_EOL;
echo 'version=', SEOAUTO_HELPER_VERSION, PHP_EOL;
" --path="$WP_ROOT" --allow-root

curl -sS -m 15 -o /dev/null -w "api %{http_code}\n" "https://${API_DOMAIN}/docs"
curl -sS -m 15 -o /dev/null -w "wp %{http_code}\n" "https://${WP_DOMAIN}/"
echo -n "prod="; systemctl is-active digiseo.service
echo -n "staging="; systemctl is-active digiseo-staging.service
echo DONE
