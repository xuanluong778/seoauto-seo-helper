#!/usr/bin/env bash
# Bootstrap MinIO as staging R2 stand-in, reachable from GitHub Actions via public IP.
# Does NOT touch production R2. Writes secrets to staging_ci_secrets.env (chmod 600).
set -euo pipefail

STAGING_ROOT="${STAGING_ROOT:-/var/www/seoauto_vn_usr/data/www-staging}"
MINIO_ROOT="${MINIO_ROOT:-/var/www/seoauto_vn_usr/data/minio-staging}"
MINIO_PORT="${MINIO_PORT:-9000}"
MINIO_CONSOLE="${MINIO_CONSOLE:-9001}"
BUCKET="seoauto-plugin-staging"
PUBLIC_HOST="${PUBLIC_HOST:-116.118.45.72}"
# Persist keys across re-runs if already present
SECRETS="$STAGING_ROOT/data/staging_ci_secrets.env"
ENV_FILE="$STAGING_ROOT/env.local"
mkdir -p "$STAGING_ROOT/bin" "$STAGING_ROOT/data" "$MINIO_ROOT"

if [[ -f "$SECRETS" ]] && grep -q '^R2_ACCESS_KEY_ID=' "$SECRETS" && grep -q '^R2_SECRET_ACCESS_KEY=' "$SECRETS"; then
  # shellcheck disable=SC1090
  set -a; source "$SECRETS"; set +a
fi
ACCESS_KEY="${R2_ACCESS_KEY_ID:-staging$(openssl rand -hex 8)}"
SECRET_KEY="${R2_SECRET_ACCESS_KEY:-$(openssl rand -hex 24)}"

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
LimitNOFILE=65536
[Install]
WantedBy=multi-user.target
EOF

chown -R seoauto_vn_usr:seoauto_vn_usr "$MINIO_ROOT" "$STAGING_ROOT/bin"
systemctl daemon-reload
systemctl enable --now minio-staging.service
sleep 3
systemctl is-active minio-staging.service

# Open firewall for Actions upload (staging only)
if command -v ufw >/dev/null 2>&1; then
  ufw allow ${MINIO_PORT}/tcp comment 'minio-staging-r2' || true
  ufw status | grep -E "${MINIO_PORT}" || true
fi
if command -v firewall-cmd >/dev/null 2>&1; then
  firewall-cmd --add-port=${MINIO_PORT}/tcp --permanent 2>/dev/null || true
  firewall-cmd --reload 2>/dev/null || true
fi

"$STAGING_ROOT/bin/mc" alias set staginglocal "http://127.0.0.1:${MINIO_PORT}" "$ACCESS_KEY" "$SECRET_KEY" >/dev/null
"$STAGING_ROOT/bin/mc" mb -p "staginglocal/${BUCKET}" 2>/dev/null || true
echo 'smoke-zip' > /tmp/seoauto-smoke.txt
"$STAGING_ROOT/bin/mc" cp /tmp/seoauto-smoke.txt "staginglocal/${BUCKET}/smoke.txt" >/dev/null
"$STAGING_ROOT/bin/mc" stat "staginglocal/${BUCKET}/smoke.txt" >/dev/null
echo "MinIO HEAD OK bucket=${BUCKET}"

# Patch SaaS staging env (local endpoint for app; path-style for MinIO)
python3 - <<PY
from pathlib import Path
env_path = Path("${ENV_FILE}")
text = env_path.read_text(encoding="utf-8") if env_path.exists() else ""
lines = [ln for ln in text.splitlines() if ln.strip() and not ln.strip().startswith("#")]
kv = {}
for ln in lines:
    if "=" in ln:
        k, v = ln.split("=", 1)
        kv[k.strip()] = v.strip()
updates = {
    "WP_PLUGIN_STORAGE_BACKEND": "r2",
    "R2_BUCKET": "${BUCKET}",
    "R2_ENDPOINT_URL": "http://127.0.0.1:${MINIO_PORT}",
    "R2_ACCESS_KEY_ID": "${ACCESS_KEY}",
    "R2_SECRET_ACCESS_KEY": "${SECRET_KEY}",
    "R2_PREFIX": "plugin-releases",
    "R2_REGION": "us-east-1",
    "R2_FORCE_PATH_STYLE": "1",
}
kv.update(updates)
out = "\n".join(f"{k}={v}" for k, v in sorted(kv.items())) + "\n"
env_path.write_text(out, encoding="utf-8")
env_path.chmod(0o600)
print("env.local updated (values not printed)")
PY

# Write CI secrets file (public endpoint for GitHub Actions)
python3 - <<PY
from pathlib import Path
secrets_path = Path("${SECRETS}")
text = secrets_path.read_text(encoding="utf-8") if secrets_path.exists() else ""
kv = {}
for ln in text.splitlines():
    if "=" in ln and not ln.strip().startswith("#"):
        k, v = ln.split("=", 1)
        kv[k.strip()] = v.strip()
# Keep existing CI token / signing key from prior bootstrap
kv.update({
    "SEOAUTO_API_BASE": "https://staging.seoauto.vn",
    "R2_BUCKET": "${BUCKET}",
    "R2_ENDPOINT_URL": "http://${PUBLIC_HOST}:${MINIO_PORT}",
    "R2_ACCESS_KEY_ID": "${ACCESS_KEY}",
    "R2_SECRET_ACCESS_KEY": "${SECRET_KEY}",
    "R2_PREFIX": "plugin-releases",
    "R2_FORCE_PATH_STYLE": "1",
    "WP_PLUGIN_STORAGE_BACKEND": "r2",
    "R2_NOTE": "minio-staging-public-ip-for-gha",
})
# Ensure CI token + signing pulled from env.local if missing
env_kv = {}
for ln in Path("${ENV_FILE}").read_text(encoding="utf-8").splitlines():
    if "=" in ln:
        k, v = ln.split("=", 1)
        env_kv[k.strip()] = v.strip()
for k in ("WP_PLUGIN_CI_RELEASE_TOKEN", "WP_PLUGIN_RELEASE_SIGNING_KEY"):
    if not kv.get(k) and env_kv.get(k):
        kv[k] = env_kv[k]
out = "\n".join(f"{k}={v}" for k, v in sorted(kv.items())) + "\n"
secrets_path.write_text(out, encoding="utf-8")
secrets_path.chmod(0o600)
print("staging_ci_secrets.env updated; keys:", ", ".join(sorted(kv.keys())))
PY

systemctl restart digiseo-staging.service
sleep 2
systemctl is-active digiseo-staging.service
curl -sk -o /dev/null -w "api %{http_code}\n" https://staging.seoauto.vn/docs
echo "MINIO_STAGING_READY port=${MINIO_PORT} bucket=${BUCKET}"
