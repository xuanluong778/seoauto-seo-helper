#!/usr/bin/env bash
set -euo pipefail
echo "prod=$(systemctl is-active digiseo.service) staging=$(systemctl is-active digiseo-staging.service)"
ss -ltnp | grep 8901 || true
curl -sS -m 5 -o /dev/null -w "docs %{http_code}\n" http://127.0.0.1:8901/docs
# Does app load dotenv from file?
python3 - <<'PY'
from pathlib import Path
import re
main = Path('/var/www/seoauto_vn_usr/data/www-staging/main.py').read_text()
for line in main.splitlines():
    if 'dotenv' in line.lower() or 'env.local' in line or 'load_dotenv' in line:
        print(line)
PY
# migrate
sudo -u seoauto_vn_usr bash -lc 'cd /var/www/seoauto_vn_usr/data/www-staging && set -a && source env.local && set +a && .venv/bin/python - <<"PY"
from app.migrations.ensure_wordpress_plugin_tables import ensure_wordpress_plugin_tables
print(ensure_wordpress_plugin_tables())
PY'
# Does engine see 5433?
sudo -u seoauto_vn_usr bash -lc 'cd /var/www/seoauto_vn_usr/data/www-staging && set -a && source env.local && set +a && .venv/bin/python - <<"PY"
import os
from app.db import DATABASE_URL, engine
print("url", DATABASE_URL.split("@")[-1] if "@" in DATABASE_URL else DATABASE_URL)
print("engine_url", str(engine.url).split("@")[-1])
PY'
echo ALL_GOOD
