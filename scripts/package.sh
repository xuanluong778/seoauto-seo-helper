#!/usr/bin/env bash
# Build seoauto-seo-helper.zip with single root folder for WordPress / CI.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="seoauto-seo-helper"
OUT_DIR="${ROOT}/dist"
STAGE="${OUT_DIR}/${SLUG}"
ZIP_PATH="${ROOT}/seoauto-seo-helper.zip"
MAIN_ENTRY="${SLUG}/seoauto-seo-helper.php"

echo "==> Running QA"
bash "${ROOT}/scripts/run-qa.sh" 2>/dev/null || powershell.exe -NoProfile -ExecutionPolicy Bypass -File "${ROOT}/scripts/run-qa.ps1"

rm -rf "${STAGE}"
rm -f "${ZIP_PATH}" "${ROOT}/seoauto-seo-helper.zip.sha256" "${ROOT}/release-manifest.json"
mkdir -p "${STAGE}"

for item in seoauto-seo-helper.php uninstall.php readme.txt README.md includes assets docs; do
  if [[ -e "${ROOT}/${item}" ]]; then
    cp -a "${ROOT}/${item}" "${STAGE}/"
  fi
done

# Strip secrets / development artifacts
find "${STAGE}" \( \
  -name '.env' -o -name '.env.local' -o -name '.git' -o -name '.gitignore' \
  -o -name 'phpunit.xml' -o -name 'composer.json' -o -name 'composer.lock' \
\) -print0 2>/dev/null | xargs -0 rm -rf 2>/dev/null || true
find "${STAGE}" -type d \( -name '.git' -o -name 'tests' -o -name 'vendor' -o -name 'node_modules' -o -name 'logs' -o -name 'cache' \) -print0 \
  | xargs -0 rm -rf 2>/dev/null || true

# Prefer zip CLI
(
  cd "${OUT_DIR}"
  zip -qr "${ZIP_PATH}" "${SLUG}"
)

# Validate structure
python3 - <<'PY' "${ZIP_PATH}" "${MAIN_ENTRY}"
import sys, zipfile
zip_path, main = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(zip_path) as z:
    names = z.namelist()
    if main not in names:
        raise SystemExit(f"ZIP missing {main}")
    roots = {n.split('/')[0] for n in names if n and not n.endswith('/')}
    # also count folder entries
    roots |= {n.split('/')[0] for n in names if '/' in n}
    if roots != {'seoauto-seo-helper'}:
        raise SystemExit(f"Bad ZIP roots: {roots}")
    if any(n.startswith('seoauto-seo-helper/seoauto-seo-helper/') for n in names):
        raise SystemExit('Double-nested plugin folder')
    # smoke: can open
    z.testzip()
print('ZIP OK:', zip_path)
PY

SHA=$(sha256sum "${ZIP_PATH}" | awk '{print $1}')
echo -n "${SHA}" > "${ROOT}/seoauto-seo-helper.zip.sha256"
echo "Created: ${ZIP_PATH}"
echo "SHA256: ${SHA}"
