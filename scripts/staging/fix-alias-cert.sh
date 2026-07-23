#!/usr/bin/env bash
set -euo pipefail
# Use siteauto cert for the siteauto alias host
python3 - <<'PY'
from pathlib import Path
p = Path('/etc/nginx/conf.d/staging.seoauto.vn.conf')
text = p.read_text()
# Split into two server blocks if needed — simpler: dual cert can't work for both names on one block with different certs easily.
# Recreate: staging.seoauto.vn keeps seoauto cert; alias gets siteauto cert.
print('rewriting nginx for dual hosts')
p.write_text('''upstream digiseo_staging {
    server 127.0.0.1:8901;
    keepalive 8;
}
server {
    listen 116.118.45.72:80;
    server_name staging.seoauto.vn seoauto-api-staging.siteauto.vn;
    include /etc/nginx/fastpanel2-includes/letsencrypt.conf;
    location / { return 301 https://$host$request_uri; }
}
server {
    listen 116.118.45.72:443 ssl;
    server_name staging.seoauto.vn;
    ssl_certificate /etc/letsencrypt/live/seoauto.vn/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/seoauto.vn/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    client_max_body_size 64M;
    location / {
        proxy_pass http://digiseo_staging;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_read_timeout 120;
    }
}
server {
    listen 116.118.45.72:443 ssl;
    server_name seoauto-api-staging.siteauto.vn;
    ssl_certificate /etc/letsencrypt/live/seo.siteauto.vn/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/seo.siteauto.vn/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    client_max_body_size 64M;
    location / {
        proxy_pass http://digiseo_staging;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_read_timeout 120;
    }
}
''')
PY
nginx -t && systemctl reload nginx
sleep 5
systemctl is-active digiseo-staging || systemctl restart digiseo-staging
sleep 12
curl -sS -m 10 -o /dev/null -w "local_docs %{http_code}\n" http://127.0.0.1:8901/docs
curl -sk -m 10 -o /dev/null -w "alias_https %{http_code}\n" https://seoauto-api-staging.siteauto.vn/docs
systemctl is-active digiseo digiseo-staging
echo CERT_FIXED
