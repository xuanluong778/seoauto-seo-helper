#!/usr/bin/env bash
# Optional: local MinIO as S3-compatible stand-in for Cloudflare R2 staging bucket.
# Bucket name: seoauto-plugin-staging. Does not use production R2 credentials.
set -euo pipefail

STAGING_ROOT="${STAGING_ROOT:-/var/www/seoauto_vn_usr/data/www-staging}"
MINIO_ROOT="${MINIO_ROOT:-/var/www/seoauto_vn_usr/data/minio-staging}"
MINIO_PORT="${MINIO_PORT:-9000}"
MINIO_CONSOLE="${MINIO_CONSOLE:-9001}"
BUCKET="seoauto-plugin-staging"
ACCESS_KEY="${R2_ACCESS_KEY_ID:-staging$(openssl rand -hex 8)}"
SECRET_KEY="${R2_SECRET_ACCESS_KEY:-$(openssl rand -hex 24)}"

mkdir -p "$MINIO_ROOT" "$STAGING_ROOT/bin"
if [[ ! -x "$STAGING_ROOT/bin/minio" ]]; then
  curl -fsSL https://dl.min.io/server/minio/release/linux-amd64/minio -o "$STAGING_ROOT/bin/minio"
  chmod +x "$STAGING_ROOT/bin/minio"
fi
if [[ ! -x "$STAGING_ROOT/bin/mc" ]]; then
  curl -fsSL https://dl.min.io/client/mc/release/linux-amd64/mc -o "$STAGING_ROOT/bin/mc"
  chmod +x "$STAGING_ROOT/bin/mc"
fi

cat > /etc/systemd/system/minio-staging.service <<EOF
[Unit]
Description=MinIO S3 staging (seoauto-plugin-staging)
After=network.target
[Service]
User=seoauto_vn_usr
Group=seoauto_vn_usr
Environment=MINIO_ROOT_USER=${ACCESS_KEY}
Environment=MINIO_ROOT_PASSWORD=${SECRET_KEY}
ExecStart=${STAGING_ROOT}/bin/minio server ${MINIO_ROOT} --address :${MINIO_PORT} --console-address :${MINIO_CONSOLE}
Restart=on-failure
[Install]
WantedBy=multi-user.target
EOF

chown -R seoauto_vn_usr:seoauto_vn_usr "$MINIO_ROOT" "$STAGING_ROOT/bin"
systemctl daemon-reload
systemctl enable --now minio-staging.service
sleep 2

"$STAGING_ROOT/bin/mc" alias set staginglocal "http://127.0.0.1:${MINIO_PORT}" "$ACCESS_KEY" "$SECRET_KEY"
"$STAGING_ROOT/bin/mc" mb -p "staginglocal/${BUCKET}" || true

# Smoke: put / head / get (do not print secret or full URL with signature)
echo 'smoke-zip' > /tmp/seoauto-smoke.txt
"$STAGING_ROOT/bin/mc" cp /tmp/seoauto-smoke.txt "staginglocal/${BUCKET}/smoke.txt"
"$STAGING_ROOT/bin/mc" stat "staginglocal/${BUCKET}/smoke.txt" >/dev/null
echo "MinIO HEAD OK for bucket/${BUCKET}/smoke.txt"

# Patch staging env to use S3-compatible endpoint (R2 adapter)
ENV_FILE="$STAGING_ROOT/env.local"
if [[ -f "$ENV_FILE" ]]; then
  grep -q '^WP_PLUGIN_STORAGE_BACKEND=' "$ENV_FILE" && sed -i 's/^WP_PLUGIN_STORAGE_BACKEND=.*/WP_PLUGIN_STORAGE_BACKEND=r2/' "$ENV_FILE" || echo 'WP_PLUGIN_STORAGE_BACKEND=r2' >> "$ENV_FILE"
  cat >> "$ENV_FILE" <<EOF
R2_BUCKET=${BUCKET}
R2_ENDPOINT_URL=http://127.0.0.1:${MINIO_PORT}
R2_ACCESS_KEY_ID=${ACCESS_KEY}
R2_SECRET_ACCESS_KEY=${SECRET_KEY}
R2_PREFIX=plugin-releases
R2_REGION=us-east-1
EOF
  chmod 600 "$ENV_FILE"
fi

SECRETS="$STAGING_ROOT/data/staging_ci_secrets.env"
cat >> "$SECRETS" <<EOF
R2_BUCKET=${BUCKET}
R2_ENDPOINT_URL=http://127.0.0.1:${MINIO_PORT}
R2_ACCESS_KEY_ID=${ACCESS_KEY}
R2_SECRET_ACCESS_KEY=${SECRET_KEY}
R2_PREFIX=plugin-releases
EOF
chmod 600 "$SECRETS"

echo "MinIO staging ready on :${MINIO_PORT} bucket=${BUCKET}"
echo "NOTE: GitHub Actions runners cannot reach 127.0.0.1 — need public R2 or tunnel for CI upload."
