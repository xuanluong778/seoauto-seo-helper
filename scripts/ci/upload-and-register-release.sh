#!/usr/bin/env bash
# Upload ZIP to R2/S3 then register draft release via SEOAuto CI API.
# Never publishes stable automatically.
set -euo pipefail

MANIFEST="${1:-release-manifest.json}"
ZIP="${2:-seoauto-seo-helper.zip}"
export MANIFEST_PATH="${MANIFEST}"

if [[ ! -f "${MANIFEST}" || ! -f "${ZIP}" ]]; then
  echo "Usage: upload-and-register-release.sh <manifest.json> <zip>" >&2
  exit 2
fi

: "${R2_BUCKET:?R2_BUCKET required}"
: "${R2_ENDPOINT_URL:?R2_ENDPOINT_URL required}"
: "${AWS_ACCESS_KEY_ID:?AWS_ACCESS_KEY_ID required}"
: "${AWS_SECRET_ACCESS_KEY:?AWS_SECRET_ACCESS_KEY required}"
: "${SEOAUTO_API_BASE:?SEOAUTO_API_BASE required}"
: "${WP_PLUGIN_CI_RELEASE_TOKEN:?WP_PLUGIN_CI_RELEASE_TOKEN required}"

STORAGE_KEY=$(python3 -c "import json;print(json.load(open('${MANIFEST}'))['storage_key'])")
PREFIX="${R2_PREFIX:-plugin-releases}"
FULL_KEY="${PREFIX%/}/${STORAGE_KEY}"

# MinIO / some S3-compatible endpoints need path-style addressing.
if [[ "${R2_FORCE_PATH_STYLE:-}" =~ ^(1|true|yes|on)$ ]]; then
  mkdir -p "${HOME}/.aws"
  cat > "${HOME}/.aws/config" <<'AWSCFG'
[default]
s3 =
    addressing_style = path
AWSCFG
fi

echo "Uploading object under prefix=${PREFIX} (full URL not logged)"
aws s3 cp "${ZIP}" "s3://${R2_BUCKET}/${FULL_KEY}" \
  --endpoint-url "${R2_ENDPOINT_URL}" \
  --content-type application/zip

python3 <<'PY'
import json, os, urllib.request

manifest_path = os.environ.get("MANIFEST_PATH", "release-manifest.json")
manifest = json.load(open(manifest_path, encoding="utf-8"))
base = os.environ["SEOAUTO_API_BASE"].rstrip("/")
token = os.environ["WP_PLUGIN_CI_RELEASE_TOKEN"]
body = {
    "version": manifest["version"],
    "channel": manifest["channel"],
    "storage_key": manifest["storage_key"],
    "sha256": manifest["sha256"],
    "signature": manifest["signature"],
    "changelog_url": manifest.get("changelog_url") or "",
    "requires_wp": manifest.get("requires_wp") or "6.0",
    "requires_php": manifest.get("requires_php") or "8.1",
    "tested_wp": manifest.get("tested_wp") or "6.7",
    "rollout_percent": int(os.environ.get("ROLLOUT_PERCENT", "100")),
    "verify_object_exists": True,
}
req = urllib.request.Request(
    f"{base}/api/wordpress-plugin/releases",
    data=json.dumps(body).encode("utf-8"),
    headers={
        "Content-Type": "application/json",
        "X-SEOAuto-CI-Token": token,
    },
    method="POST",
)
with urllib.request.urlopen(req, timeout=60) as resp:
    raw = resp.read().decode("utf-8")
    print(raw)
    data = json.loads(raw)
    if not data.get("ok"):
        raise SystemExit("create release failed")
    status = (data.get("release") or {}).get("status")
    if status == "published":
        raise SystemExit("refusing unexpected published status from create")
print("Draft release registered OK")
PY
