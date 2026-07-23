#!/usr/bin/env bash
# Emit one secret value to stdout (for piping into gh secret set). Arg1 = key name.
set -euo pipefail
KEY="${1:?key}"
python3 - <<PY
from pathlib import Path
import sys
p = Path("/var/www/seoauto_vn_usr/data/www-staging/data/staging_ci_secrets.env")
kv = {}
for ln in p.read_text(encoding="utf-8").splitlines():
    if "=" in ln and not ln.strip().startswith("#"):
        a, b = ln.split("=", 1)
        kv[a.strip()] = b.strip()
sys.stdout.write(kv.get("${KEY}", ""))
PY
