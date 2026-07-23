#!/usr/bin/env bash
set -euo pipefail
STAGING_ROOT=/var/www/seoauto_vn_usr/data/www-staging
DB_PASS=$(tr -d '\r\n' < "$STAGING_ROOT/data/staging_db.pass")
# Confirm role on host PG 5433
sudo -u postgres env PGPORT=5433 psql -c "\du seoauto_staging"
# Confirm DATABASE_URL uses 5433
grep '^DATABASE_URL=' "$STAGING_ROOT/env.local" | sed -E 's#(://)[^:]+:[^@]+@#\1***:***@#'
# Ensure dotenv / app picks 5433 — rewrite if needed
python3 - <<'PY'
from pathlib import Path
import re
p = Path("/var/www/seoauto_vn_usr/data/www-staging/env.local")
text = p.read_text()
text2 = re.sub(
    r"(DATABASE_URL=postgresql\+psycopg2://seoauto_staging:[^@]+@127\.0\.0\.1:)\d+(/seoauto_staging)",
    r"\g<1>5433\g<2>",
    text,
)
p.write_text(text2)
print("env port ok" if ":5433/" in text2 else "WARN env port")
PY
chmod 600 "$STAGING_ROOT/env.local"
chown seoauto_vn_usr:seoauto_vn_usr "$STAGING_ROOT/env.local"
# How does app.db load DATABASE_URL?
python3 - <<'PY'
from pathlib import Path
p = Path("/var/www/seoauto_vn_usr/data/www-staging/app/db.py")
print(p.read_text()[:1500])
PY
systemctl restart digiseo-staging.service
sleep 20
systemctl is-active digiseo-staging.service || { journalctl -u digiseo-staging -n 40 --no-pager; exit 1; }
ss -ltnp | grep 8901
curl -sS -m 5 -o /dev/null -w "docs %{http_code}\n" http://127.0.0.1:8901/docs
echo STAGING_UP
