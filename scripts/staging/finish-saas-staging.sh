#!/usr/bin/env bash
set -euo pipefail
STAGING_ROOT=/var/www/seoauto_vn_usr/data/www-staging
DB_PASS=$(tr -d '\r\n' < "$STAGING_ROOT/data/staging_db.pass")
# Recreate secrets file keys if needed but keep tokens
python3 - <<'PY'
from pathlib import Path
import re, os
p = Path("/var/www/seoauto_vn_usr/data/www-staging/env.local")
text = p.read_text()
# force port 5433
text = re.sub(r"(DATABASE_URL=postgresql\+psycopg2://seoauto_staging:[^@]+@127\.0\.0\.1:)\d+(/seoauto_staging)",
              r"\g<1>5433\g<2>", text)
p.write_text(text)
print("port", "5433" if ":5433/" in text else "BAD")
PY
chmod 600 "$STAGING_ROOT/env.local"
chown seoauto_vn_usr:seoauto_vn_usr "$STAGING_ROOT/env.local"
# grant on host pg
sudo -u postgres env PGPORT=5433 psql -d seoauto_staging -c "GRANT ALL ON SCHEMA public TO seoauto_staging; ALTER SCHEMA public OWNER TO seoauto_staging;"
systemctl restart digiseo-staging.service
sleep 25
systemctl is-active digiseo-staging.service || { journalctl -u digiseo-staging -n 50 --no-pager; exit 1; }
ss -ltnp | grep 8901
curl -sS -m 5 -o /dev/null -w "docs %{http_code}\n" http://127.0.0.1:8901/docs
# migrate
sudo -u seoauto_vn_usr bash -lc 'cd /var/www/seoauto_vn_usr/data/www-staging && set -a && source env.local && set +a && .venv/bin/python - <<"PY"
from app.migrations.ensure_wordpress_plugin_tables import ensure_wordpress_plugin_tables
print(ensure_wordpress_plugin_tables())
PY'
# confirm prod still up
systemctl is-active digiseo.service
curl -sS -m 5 -o /dev/null -w "prod_local_docs_skip\n" http://127.0.0.1:8899/docs || true
echo STAGING_READY
