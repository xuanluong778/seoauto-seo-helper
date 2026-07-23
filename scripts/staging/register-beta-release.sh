#!/usr/bin/env bash
# Build is done on Windows; this registers uploaded ZIP as published beta on staging.
set -euo pipefail
ZIP="${1:?zip path}"
VERSION="${2:-1.1.0-beta.1}"
CHANNEL="${3:-beta}"
cd /var/www/seoauto_vn_usr/data/www-staging
set -a; source env.local; set +a
TOKEN=$(grep '^WP_PLUGIN_CI_RELEASE_TOKEN=' env.local | cut -d= -f2-)
SIGN_KEY=$(grep '^WP_PLUGIN_RELEASE_SIGNING_KEY=' env.local | cut -d= -f2-)
SHA=$(sha256sum "$ZIP" | awk '{print $1}')
KEY="seoauto-seo-helper/${CHANNEL}/${VERSION}/seoauto-seo-helper-${VERSION}.zip"
mkdir -p "data/plugin_releases/$(dirname "$KEY")"
cp -f "$ZIP" "data/plugin_releases/$KEY"
chown -R seoauto_vn_usr:seoauto_vn_usr data/plugin_releases
SIG=$(python3 - <<PY
import hmac,hashlib,os
key=os.environ["WP_PLUGIN_RELEASE_SIGNING_KEY"]
payload=f"${VERSION}|${SHA}|${CHANNEL}"
print(hmac.new(key.encode(), payload.encode(), hashlib.sha256).hexdigest())
PY
)
# create draft
curl -sS -X POST "http://127.0.0.1:8901/api/wordpress-plugin/releases" \
  -H "Content-Type: application/json" \
  -H "X-SEOAuto-CI-Token: $TOKEN" \
  -d "{\"version\":\"$VERSION\",\"channel\":\"$CHANNEL\",\"storage_key\":\"$KEY\",\"sha256\":\"$SHA\",\"signature\":\"$SIG\",\"requires_wp\":\"6.0\",\"requires_php\":\"8.1\",\"tested_wp\":\"6.7\",\"changelog_url\":\"https://seoauto-api-staging.siteauto.vn/docs\",\"rollout_percent\":100,\"verify_object_exists\":true}" \
  | tee /tmp/release_create.json
# publish
curl -sS -X POST "http://127.0.0.1:8901/api/wordpress-plugin/releases/${VERSION}/publish?channel=${CHANNEL}" \
  -H "Content-Type: application/json" \
  -H "X-SEOAuto-CI-Token: $TOKEN" \
  -d '{}' | tee /tmp/release_publish.json
echo
echo REGISTERED sha=$SHA key=$KEY
