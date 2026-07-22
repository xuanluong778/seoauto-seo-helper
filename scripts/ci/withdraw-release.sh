#!/usr/bin/env bash
# Withdraw a bad release (keeps prior published versions for rollback).
set -euo pipefail
VERSION="${1:?version}"
CHANNEL="${2:-stable}"
REASON="${3:-withdrawn by operator}"
: "${SEOAUTO_API_BASE:?}"
: "${WP_PLUGIN_CI_RELEASE_TOKEN:?}"

python3 - <<PY
import json, os, urllib.request
base = os.environ["SEOAUTO_API_BASE"].rstrip("/")
token = os.environ["WP_PLUGIN_CI_RELEASE_TOKEN"]
version = "${VERSION}".lstrip("vV")
channel = "${CHANNEL}"
body = json.dumps({"channel": channel, "reason": """${REASON}"""}).encode()
req = urllib.request.Request(
    f"{base}/api/wordpress-plugin/releases/{version}/withdraw",
    data=body,
    headers={"Content-Type": "application/json", "X-SEOAuto-CI-Token": token},
    method="POST",
)
with urllib.request.urlopen(req, timeout=60) as resp:
    print(resp.read().decode())
PY
