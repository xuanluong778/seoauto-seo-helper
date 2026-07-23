#!/usr/bin/env bash
# Verify tag / header / constant / readme versions agree. Reject -dev on stable.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TAG_VERSION="${1:-}"
CHANNEL="${2:-stable}"

if [[ -z "${TAG_VERSION}" ]]; then
  echo "Usage: verify-version.sh <version> [stable|beta]" >&2
  exit 2
fi

# Strip leading v
VER="${TAG_VERSION#v}"

HEADER_VER=$(grep -E '^ \* Version:' "${ROOT}/seoauto-seo-helper.php" | head -1 | sed -E 's/.*Version:[[:space:]]*//')
CONST_VER=$(grep -E "define\(\s*'SEOAUTO_HELPER_VERSION'" "${ROOT}/seoauto-seo-helper.php" | sed -E "s/.*'SEOAUTO_HELPER_VERSION',\s*'([^']+)'.*/\1/")
README_VER=$(grep -E '^Stable tag:' "${ROOT}/readme.txt" | head -1 | sed -E 's/.*Stable tag:[[:space:]]*//' | tr -d '\r')

echo "tag=${VER} header=${HEADER_VER} const=${CONST_VER} readme=${README_VER} channel=${CHANNEL}"

if [[ "${VER}" != "${HEADER_VER}" || "${VER}" != "${CONST_VER}" ]]; then
  echo "ERROR: version mismatch between tag, plugin header, and SEOAUTO_HELPER_VERSION" >&2
  exit 1
fi

if [[ "${CHANNEL}" == "stable" && "${README_VER}" != "${VER}" ]]; then
  echo "ERROR: readme.txt Stable tag must equal ${VER} for stable channel" >&2
  exit 1
fi

if [[ "${CHANNEL}" == "stable" && "${VER}" =~ -(dev|alpha|rc) ]]; then
  echo "ERROR: stable releases cannot use -dev / -alpha / -rc suffix" >&2
  exit 1
fi

echo "Version checks OK"
