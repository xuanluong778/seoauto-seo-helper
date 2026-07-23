#!/usr/bin/env bash
# Publish beta release on staging after Actions draft PASS. Does not print tokens.
set -euo pipefail
VERSION="${1:?version}"
CHANNEL="${2:-beta}"
ENV=/var/www/seoauto_vn_usr/data/www-staging/env.local
TOKEN=$(grep "^WP_PLUGIN_CI_RELEASE_TOKEN=" "$ENV" | cut -d= -f2-)
# Prefer local loopback to staging service
BASE="http://127.0.0.1:8901"
echo "Publishing ${VERSION} channel=${CHANNEL}"
curl -sS -X POST "${BASE}/api/wordpress-plugin/releases/${VERSION}/publish?channel=${CHANNEL}" \
  -H "Content-Type: application/json" \
  -H "X-SEOAuto-CI-Token: ${TOKEN}" \
  -d '{}' | python3 -c 'import sys,json; d=json.load(sys.stdin); r=d.get("release") or {}; print("ok=", d.get("ok"), "status=", r.get("status"), "version=", r.get("version"), "channel=", r.get("channel"));
assert d.get("ok") and r.get("status")=="published", d'
echo PUBLISH_OK
