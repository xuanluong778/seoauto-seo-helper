#!/usr/bin/env bash
# CI entry: run PHP QA suites (Linux). Falls back to listing if php missing.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP_BIN:-php}"

if ! command -v "${PHP}" >/dev/null 2>&1; then
  echo "php not found" >&2
  exit 1
fi

cd "${ROOT}"
suites=(
  tests/test_load_all_classes.php
  tests/test_boot_smoke.php
  tests/test_private_updater.php
  tests/test_seo_audit_engine.php
  tests/test_lifecycle.php
  tests/test_security.php
  tests/test_entitlement_lock.php
  tests/test_network_grace.php
)

# Prefer run-qa.ps1 semantics via explicit list when on Linux
if [[ -f scripts/run-qa.ps1 ]]; then
  # Extract php test files already listed in run-qa if present
  mapfile -t discovered < <(grep -oE 'tests/test_[a-z0-9_]+\.php' scripts/run-qa.ps1 | sort -u || true)
  if ((${#discovered[@]})); then
    suites=("${discovered[@]}")
  fi
fi

for t in "${suites[@]}"; do
  if [[ -f "${t}" ]]; then
    echo "-- ${t}"
    "${PHP}" "${t}"
  fi
done
echo "QA complete."
