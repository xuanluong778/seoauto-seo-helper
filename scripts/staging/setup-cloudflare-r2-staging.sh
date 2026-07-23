#!/usr/bin/env bash
# Provision Cloudflare R2 staging bucket (seoauto-plugin-staging) WITHOUT using production secrets.
# Required env (do not commit):
#   CF_API_TOKEN   — Account API token with R2 edit
#   CF_ACCOUNT_ID  — Cloudflare account id
# Optional:
#   R2_BUCKET=seoauto-plugin-staging
# Writes credentials into staging env.local + staging_ci_secrets.env (chmod 600). Never prints secrets.
set -euo pipefail

: "${CF_API_TOKEN:?CF_API_TOKEN required}"
: "${CF_ACCOUNT_ID:?CF_ACCOUNT_ID required}"
BUCKET="${R2_BUCKET:-seoauto-plugin-staging}"
STAGING_ROOT="${STAGING_ROOT:-/var/www/seoauto_vn_usr/data/www-staging}"
ENV_FILE="$STAGING_ROOT/env.local"
SECRETS="$STAGING_ROOT/data/staging_ci_secrets.env"
API="https://api.cloudflare.com/client/v4/accounts/${CF_ACCOUNT_ID}/r2/buckets"

echo "==> Ensure R2 bucket ${BUCKET}"
HTTP=$(curl -sS -o /tmp/cf_bucket.json -w "%{http_code}" -X POST "$API" \
  -H "Authorization: Bearer ${CF_API_TOKEN}" \
  -H "Content-Type: application/json" \
  --data "{\"name\":\"${BUCKET}\"}")
if [[ "$HTTP" != "200" && "$HTTP" != "409" ]]; then
  # 409 may not be used; check success false + already exists
  python3 - <<'PY'
import json,sys
d=json.load(open("/tmp/cf_bucket.json"))
errs=d.get("errors") or []
msg=" ".join(e.get("message","") for e in errs)
if "already exists" in msg.lower() or d.get("success"):
    sys.exit(0)
print("bucket_create_failed", d)
sys.exit(1)
PY
fi
echo "bucket_ok http=$HTTP"

echo "==> Create R2 API token (S3 credentials) for staging only"
# Cloudflare R2 S3 credentials endpoint
TOK_HTTP=$(curl -sS -o /tmp/cf_r2_token.json -w "%{http_code}" -X POST \
  "https://api.cloudflare.com/client/v4/accounts/${CF_ACCOUNT_ID}/r2/tokens" \
  -H "Authorization: Bearer ${CF_API_TOKEN}" \
  -H "Content-Type: application/json" \
  --data "{\"name\":\"seoauto-plugin-staging-$(date +%s)\",\"permissions\":{\"bucket\":[\"${BUCKET}\"]},\"policies\":[{\"effect\":\"allow\",\"actions\":[\"AdminReadWrite\"],\"resources\":{\"com.cloudflare.edge.r2.bucket.${CF_ACCOUNT_ID}_${BUCKET}\":\"*\"}}]}") || true

python3 - <<PY
import json, os, sys
from pathlib import Path
from datetime import datetime

account = os.environ["CF_ACCOUNT_ID"]
bucket = "${BUCKET}"
endpoint = f"https://{account}.r2.cloudflarestorage.com"

# Prefer newly created token; else require pre-supplied R2_ACCESS_KEY_ID/SECRET
tok_path = Path("/tmp/cf_r2_token.json")
ak = os.environ.get("R2_ACCESS_KEY_ID", "").strip()
sk = os.environ.get("R2_SECRET_ACCESS_KEY", "").strip()
if tok_path.exists():
    try:
        d = json.loads(tok_path.read_text())
        res = d.get("result") or {}
        ak = (res.get("accessKeyId") or res.get("access_key_id") or ak).strip()
        sk = (res.get("secretAccessKey") or res.get("secret_access_key") or sk).strip()
        if not d.get("success") and not (ak and sk):
            print("token_create_note", json.dumps(d)[:300])
    except Exception as e:
        print("token_parse", type(e).__name__)

if not ak or not sk:
    print("NEED_R2_S3_KEYS: set R2_ACCESS_KEY_ID and R2_SECRET_ACCESS_KEY (from Cloudflare dashboard → R2 → Manage R2 API Tokens)")
    sys.exit(2)

env_path = Path("${ENV_FILE}")
sec_path = Path("${SECRETS}")
sec_path.parent.mkdir(parents=True, exist_ok=True)

def load(p: Path):
    kv = {}
    if p.exists():
        for ln in p.read_text(encoding="utf-8").splitlines():
            if "=" in ln and not ln.strip().startswith("#"):
                k, v = ln.split("=", 1)
                kv[k.strip()] = v.strip()
    return kv

def save(p: Path, kv: dict):
    # Remove MinIO-only force path style for real Cloudflare R2
    kv.pop("R2_FORCE_PATH_STYLE", None)
    out = "\n".join(f"{k}={v}" for k, v in sorted(kv.items())) + "\n"
    p.write_text(out, encoding="utf-8")
    p.chmod(0o600)

ek = load(env_path)
ek.update({
    "WP_PLUGIN_STORAGE_BACKEND": "r2",
    "R2_BUCKET": bucket,
    "R2_ENDPOINT_URL": endpoint,
    "R2_ACCESS_KEY_ID": ak,
    "R2_SECRET_ACCESS_KEY": sk,
    "R2_PREFIX": "plugin-releases",
    "R2_REGION": "auto",
})
save(env_path, ek)

skv = load(sec_path)
# keep CI token / signing from previous staging
for k in ("WP_PLUGIN_CI_RELEASE_TOKEN", "WP_PLUGIN_RELEASE_SIGNING_KEY", "SEOAUTO_API_BASE"):
    if ek.get(k) and not skv.get(k):
        skv[k] = ek[k]
skv.update({
    "SEOAUTO_API_BASE": skv.get("SEOAUTO_API_BASE") or "https://staging.seoauto.vn",
    "R2_BUCKET": bucket,
    "R2_ENDPOINT_URL": endpoint,
    "R2_ACCESS_KEY_ID": ak,
    "R2_SECRET_ACCESS_KEY": sk,
    "R2_PREFIX": "plugin-releases",
    "WP_PLUGIN_STORAGE_BACKEND": "r2",
    "R2_NOTE": f"cloudflare-r2-staging@{datetime.utcnow().isoformat()}Z",
})
# copy signing/ci from env if present
for k in ("WP_PLUGIN_CI_RELEASE_TOKEN", "WP_PLUGIN_RELEASE_SIGNING_KEY"):
    if ek.get(k):
        skv[k] = ek[k]
skv.pop("R2_FORCE_PATH_STYLE", None)
save(sec_path, skv)
print("R2_STAGING_CONFIGURED bucket=", bucket, "endpoint_host=", endpoint.split("//",1)[-1].split("/")[0])
print("NOTE: stop minio-staging after smoke tests pass; do not restart digiseo production")
PY

echo "==> Smoke put/get/head via awscli (if available) or python boto3"
set -a
# shellcheck disable=SC1090
source <(grep -E '^(R2_|WP_PLUGIN_STORAGE)' "$ENV_FILE")
set +a
python3 - <<'PY'
import os, sys, time
sys.path.insert(0, "/var/www/seoauto_vn_usr/data/www-staging")
# Load env into process
from pathlib import Path
for ln in Path("/var/www/seoauto_vn_usr/data/www-staging/env.local").read_text().splitlines():
    if "=" in ln and not ln.startswith("#"):
        k,v=ln.split("=",1); os.environ[k.strip()]=v.strip()
from app.services.storage_adapter import get_storage_adapter
ad = get_storage_adapter()
key = f"_smoke/{int(time.time())}.txt"
ad.put_bytes(key, b"r2-staging-smoke")
assert ad.exists(key), "exists failed"
assert ad.get_bytes(key) == b"r2-staging-smoke", "get failed"
url = ad.presign_get(key, expires_in=60)
assert url and "http" in url, url
print("smoke_put_get_presign_ok")
# expiry: mint very short URL (cannot easily assert expiry without waiting)
url2 = ad.presign_get(key, expires_in=1)
print("smoke_presign_short_ok", bool(url2))
# missing object
assert ad.exists("missing/no-such-object.zip") is False
print("smoke_missing_ok")
ad.delete(key)
print("R2_SMOKE_PASS")
PY

systemctl restart digiseo-staging.service
sleep 5
curl -sk -o /dev/null -w "api %{http_code}\n" https://staging.seoauto.vn/docs
echo "DONE — update GitHub secrets from staging_ci_secrets.env (names only via set-gh-secrets.ps1)"
