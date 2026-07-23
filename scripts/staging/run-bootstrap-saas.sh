#!/usr/bin/env bash
set -euo pipefail
STAGING_ROOT=/var/www/seoauto_vn_usr/data/www-staging
STAGING_USER=seoauto_vn_usr
STAGING_PORT=8901
STAGING_DB=seoauto_staging
STAGING_DB_USER=seoauto_staging
REDIS_DB=5
SOURCE_COMMIT=f2b1f1f
DEV_VENV=/var/www/seoauto_vn_usr/data/www/.venv

echo "==> Ensure production untouched"
systemctl is-active digiseo.service

if [[ ! -d "$STAGING_ROOT/.git" ]]; then
  rm -rf "$STAGING_ROOT"
  sudo -u "$STAGING_USER" git clone --depth 50 --branch feature/seo-helper-release-cicd https://github.com/xuanluong778/seoauto778.git "$STAGING_ROOT"
fi
cd "$STAGING_ROOT"
sudo -u "$STAGING_USER" git fetch --depth 50 origin feature/seo-helper-release-cicd || true
sudo -u "$STAGING_USER" git checkout -f "$SOURCE_COMMIT"
echo "Checked out $(git rev-parse --short HEAD)"

if [[ ! -e "$STAGING_ROOT/.venv" ]]; then
  ln -s "$DEV_VENV" "$STAGING_ROOT/.venv"
fi
"$STAGING_ROOT/.venv/bin/pip" show boto3 >/dev/null 2>&1 || "$STAGING_ROOT/.venv/bin/pip" install 'boto3>=1.34' 'botocore>=1.34'

mkdir -p "$STAGING_ROOT/data"
PASS_FILE="$STAGING_ROOT/data/staging_db.pass"
if [[ ! -f "$PASS_FILE" ]]; then
  openssl rand -hex 16 > "$PASS_FILE"
  chmod 600 "$PASS_FILE"
  chown "$STAGING_USER:$STAGING_USER" "$PASS_FILE"
fi
DB_PASS=$(cat "$PASS_FILE")

sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${STAGING_DB_USER}') THEN
    CREATE ROLE ${STAGING_DB_USER} LOGIN PASSWORD '${DB_PASS}';
  END IF;
END
\$\$;
SELECT 'CREATE DATABASE ${STAGING_DB} OWNER ${STAGING_DB_USER}'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${STAGING_DB}')\gexec
GRANT ALL PRIVILEGES ON DATABASE ${STAGING_DB} TO ${STAGING_DB_USER};
ALTER ROLE ${STAGING_DB_USER} PASSWORD '${DB_PASS}';
SQL

SIGNING_KEY=$(openssl rand -hex 32)
CI_TOKEN=$(openssl rand -hex 32)
SECRET_KEY=$(openssl rand -hex 32)
JWT_SECRET=$(openssl rand -hex 32)

cat > "$STAGING_ROOT/env.local" <<EOF
SEOAUTO_ENV=staging
APP_BASE_URL=https://staging.seoauto.vn
PUBLIC_BASE_URL=https://staging.seoauto.vn
SEOAUTO_REQUIRE_POSTGRES=1
DATABASE_URL=postgresql+psycopg2://${STAGING_DB_USER}:${DB_PASS}@127.0.0.1:5432/${STAGING_DB}
REDIS_URL=redis://127.0.0.1:6379/${REDIS_DB}
SECRET_KEY=${SECRET_KEY}
JWT_SECRET=${JWT_SECRET}
WP_PLUGIN_STORAGE_BACKEND=local
WP_PLUGIN_RELEASE_STORAGE=${STAGING_ROOT}/data/plugin_releases
WP_PLUGIN_CI_RELEASE_TOKEN=${CI_TOKEN}
WP_PLUGIN_RELEASE_SIGNING_KEY=${SIGNING_KEY}
WP_PLUGIN_DOWNLOAD_TTL_SECONDS=900
WP_PLUGIN_MIN_KEEP_RELEASES=3
BENCH_FAST=1
EOF
chmod 600 "$STAGING_ROOT/env.local"
mkdir -p "$STAGING_ROOT/data/plugin_releases" "$STAGING_ROOT/data/logs"
cat > "$STAGING_ROOT/data/staging_ci_secrets.env" <<EOF
SEOAUTO_API_BASE=https://staging.seoauto.vn
WP_PLUGIN_CI_RELEASE_TOKEN=${CI_TOKEN}
WP_PLUGIN_RELEASE_SIGNING_KEY=${SIGNING_KEY}
WP_PLUGIN_STORAGE_BACKEND=local
R2_BUCKET=seoauto-plugin-staging
R2_NOTE=local-storage-until-R2-credentials
EOF
chmod 600 "$STAGING_ROOT/data/staging_ci_secrets.env" "$STAGING_ROOT/env.local"
chown -R "$STAGING_USER:$STAGING_USER" "$STAGING_ROOT/data" "$STAGING_ROOT/env.local"

mkdir -p "$STAGING_ROOT/scripts"
cat > "$STAGING_ROOT/scripts/digiseo_staging_start.sh" <<EOF
#!/usr/bin/env bash
set -euo pipefail
APP_DIR="${STAGING_ROOT}"
cd "\$APP_DIR"
set -a
source "\$APP_DIR/env.local"
set +a
export PYTHONUNBUFFERED=1 PYTHONPATH="\$APP_DIR"
exec "\$APP_DIR/.venv/bin/uvicorn" main:app --host 127.0.0.1 --port ${STAGING_PORT} --workers 1
EOF
chmod +x "$STAGING_ROOT/scripts/digiseo_staging_start.sh"

cat > /etc/systemd/system/digiseo-staging.service <<EOF
[Unit]
Description=DigiSEO FastAPI STAGING (staging.seoauto.vn)
After=network.target redis-server.service postgresql.service
[Service]
Type=simple
User=${STAGING_USER}
Group=${STAGING_USER}
WorkingDirectory=${STAGING_ROOT}
Environment=PYTHONUNBUFFERED=1
Environment=PYTHONPATH=${STAGING_ROOT}
ExecStart=${STAGING_ROOT}/scripts/digiseo_staging_start.sh
Restart=on-failure
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF

cat > /etc/nginx/conf.d/staging.seoauto.vn.conf <<'NGX'
upstream digiseo_staging {
    server 127.0.0.1:8901;
    keepalive 8;
}
server {
    listen 116.118.45.72:80;
    server_name staging.seoauto.vn;
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
NGX

chown -R "$STAGING_USER:$STAGING_USER" "$STAGING_ROOT/scripts"
systemctl daemon-reload
nginx -t
systemctl reload nginx
systemctl enable digiseo-staging.service
systemctl restart digiseo-staging.service
sleep 4
echo "==> Services"
systemctl is-active digiseo.service
systemctl is-active digiseo-staging.service || { journalctl -u digiseo-staging.service -n 40 --no-pager; exit 1; }
curl -sS -o /tmp/stg_health.txt -w "HTTP %{http_code}\n" "http://127.0.0.1:${STAGING_PORT}/docs" || true
head -c 200 /tmp/stg_health.txt; echo

sudo -u "$STAGING_USER" bash -lc "cd '$STAGING_ROOT' && set -a && source env.local && set +a && .venv/bin/python -c 'from app.migrations.ensure_wordpress_plugin_tables import ensure_wordpress_plugin_tables; print(ensure_wordpress_plugin_tables())'"

echo DONE_STAGING_BOOTSTRAP
