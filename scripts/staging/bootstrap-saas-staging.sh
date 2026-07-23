#!/usr/bin/env bash
# Bootstrap SEOAuto staging on the SAME host as production — isolated paths/ports/DB/redis.
# Does NOT restart or modify digiseo.service / seoauto.vn.
set -euo pipefail

STAGING_ROOT="${STAGING_ROOT:-/var/www/seoauto_vn_usr/data/www-staging}"
STAGING_USER="${STAGING_USER:-seoauto_vn_usr}"
STAGING_PORT="${STAGING_PORT:-8901}"
STAGING_DB="${STAGING_DB:-seoauto_staging}"
STAGING_DB_USER="${STAGING_DB_USER:-seoauto_staging}"
REDIS_DB="${REDIS_DB:-5}"
SOURCE_COMMIT="${SOURCE_COMMIT:-f2b1f1f}"
# Prefer local Windows-synced tree on server if present; else clone from GitHub.
DEV_TREE="${DEV_TREE:-/var/www/seoauto_vn_usr/data/www}"
GIT_REMOTE="${GIT_REMOTE:-https://github.com/xuanluong778/seoauto778.git}"

echo "==> Staging root: $STAGING_ROOT (port $STAGING_PORT)"

if systemctl is-active --quiet digiseo.service; then
  echo "OK: production digiseo.service is running — will not touch it"
fi

# --- directories ---
if [[ ! -d "$STAGING_ROOT/.git" ]]; then
  mkdir -p "$(dirname "$STAGING_ROOT")"
  if [[ -d "$DEV_TREE/.git" ]]; then
    echo "==> Cloning from local tree $DEV_TREE @ $SOURCE_COMMIT"
    git clone --shared "$DEV_TREE" "$STAGING_ROOT"
  else
    echo "==> Cloning from $GIT_REMOTE"
    git clone "$GIT_REMOTE" "$STAGING_ROOT"
  fi
fi
cd "$STAGING_ROOT"
git fetch --all --tags 2>/dev/null || true
git checkout -f "$SOURCE_COMMIT"

# --- Python venv ---
if [[ ! -x "$STAGING_ROOT/.venv/bin/python" ]]; then
  if [[ -x "$DEV_TREE/.venv/bin/python" ]]; then
    python3 -m venv --system-site-packages "$STAGING_ROOT/.venv" || python3 -m venv "$STAGING_ROOT/.venv"
  else
    python3 -m venv "$STAGING_ROOT/.venv"
  fi
  "$STAGING_ROOT/.venv/bin/pip" install -U pip wheel
  "$STAGING_ROOT/.venv/bin/pip" install -r requirements.txt
fi

# --- Postgres role/db (isolated) ---
DB_PASS="${STAGING_DB_PASS:-$(openssl rand -hex 16)}"
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
SQL
# If role already existed, rotate only when STAGING_DB_PASS provided
if [[ -n "${STAGING_DB_PASS:-}" ]]; then
  sudo -u postgres psql -v ON_ERROR_STOP=1 -c "ALTER ROLE ${STAGING_DB_USER} PASSWORD '${DB_PASS}';"
fi

# Persist generated password for env if newly created
PASS_FILE="$STAGING_ROOT/data/staging_db.pass"
mkdir -p "$STAGING_ROOT/data"
if [[ ! -f "$PASS_FILE" ]]; then
  echo -n "$DB_PASS" > "$PASS_FILE"
  chmod 600 "$PASS_FILE"
else
  DB_PASS="$(cat "$PASS_FILE")"
fi

# --- Staging secrets (never copy production env.local) ---
SIGNING_KEY="${WP_PLUGIN_RELEASE_SIGNING_KEY:-$(openssl rand -hex 32)}"
CI_TOKEN="${WP_PLUGIN_CI_RELEASE_TOKEN:-$(openssl rand -hex 32)}"
SECRET_KEY="${STAGING_SECRET_KEY:-$(openssl rand -hex 32)}"
JWT_SECRET="${STAGING_JWT_SECRET:-$(openssl rand -hex 32)}"

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

# Save CI secrets for operator (not world-readable)
cat > "$STAGING_ROOT/data/staging_ci_secrets.env" <<EOF
SEOAUTO_API_BASE=https://staging.seoauto.vn
WP_PLUGIN_CI_RELEASE_TOKEN=${CI_TOKEN}
WP_PLUGIN_RELEASE_SIGNING_KEY=${SIGNING_KEY}
WP_PLUGIN_STORAGE_BACKEND=local
R2_NOTE=Replace with R2 staging when bucket seoauto-plugin-staging credentials are available
EOF
chmod 600 "$STAGING_ROOT/data/staging_ci_secrets.env"

# --- Start script ---
cat > "$STAGING_ROOT/scripts/digiseo_staging_start.sh" <<EOF
#!/usr/bin/env bash
set -euo pipefail
APP_DIR="${STAGING_ROOT}"
cd "\$APP_DIR"
set -a
# shellcheck disable=SC1091
source "\$APP_DIR/env.local"
set +a
export PYTHONUNBUFFERED=1
export PYTHONPATH="\$APP_DIR"
exec "\$APP_DIR/.venv/bin/uvicorn" main:app --host 127.0.0.1 --port ${STAGING_PORT} --workers 1
EOF
chmod +x "$STAGING_ROOT/scripts/digiseo_staging_start.sh"

# --- systemd (separate from digiseo.service) ---
cat > /etc/systemd/system/digiseo-staging.service <<EOF
[Unit]
Description=DigiSEO FastAPI STAGING (staging.seoauto.vn)
After=network.target redis-server.service postgresql.service
Wants=redis-server.service postgresql.service

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
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
EOF

# --- nginx ---
cat > /etc/nginx/conf.d/staging.seoauto.vn.conf <<EOF
upstream digiseo_staging {
    server 127.0.0.1:${STAGING_PORT};
    keepalive 8;
}

server {
    listen 116.118.45.72:80;
    server_name staging.seoauto.vn;
    include /etc/nginx/fastpanel2-includes/letsencrypt.conf;
    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 116.118.45.72:443 ssl;
    server_name staging.seoauto.vn;
    # Cert issued after DNS A record exists:
    #   certbot certonly --webroot -w /var/www/html -d staging.seoauto.vn
    # Until then, reuse self-signed or skip SSL test via HTTP-only temporarily.
    ssl_certificate /etc/letsencrypt/live/seoauto.vn/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/seoauto.vn/privkey.pem;
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

chown -R "${STAGING_USER}:${STAGING_USER}" "$STAGING_ROOT"
systemctl daemon-reload
nginx -t
systemctl reload nginx
systemctl enable digiseo-staging.service
systemctl restart digiseo-staging.service

# --- migrations ---
sudo -u "$STAGING_USER" bash -lc "cd '$STAGING_ROOT' && set -a && source env.local && set +a && .venv/bin/python - <<'PY'
from app.migrations.ensure_wordpress_plugin_tables import ensure_wordpress_plugin_tables
print(ensure_wordpress_plugin_tables())
PY"

echo "==> Health"
sleep 2
curl -fsS "http://127.0.0.1:${STAGING_PORT}/health" || curl -fsS "http://127.0.0.1:${STAGING_PORT}/" || true
echo
echo "==> DONE. Secrets file: $STAGING_ROOT/data/staging_ci_secrets.env"
echo "Add DNS A: staging.seoauto.vn -> $(curl -fsS ifconfig.me || hostname -I | awk '{print $1}')"
echo "Then: certbot certonly --nginx -d staging.seoauto.vn  (update nginx ssl paths)"
echo "Production digiseo.service was NOT restarted."
systemctl is-active digiseo.service digiseo-staging.service
