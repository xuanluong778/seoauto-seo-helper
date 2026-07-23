#!/usr/bin/env bash
set -euo pipefail
STAGING_ROOT=/var/www/seoauto_vn_usr/data/www-staging
git config --global --add safe.directory "$STAGING_ROOT" || true
cd "$STAGING_ROOT"
echo "HEAD=$(git rev-parse --short HEAD)"

# Fix DATABASE_URL to host PG 5433
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
print("db_url_hostport", text2.split("DATABASE_URL=")[1].split("\n")[0].split("@")[-1])
PY
chmod 600 "$STAGING_ROOT/env.local"
chown seoauto_vn_usr:seoauto_vn_usr "$STAGING_ROOT/env.local"

DB_PASS=$(tr -d '\r\n' < "$STAGING_ROOT/data/staging_db.pass")
sudo -u postgres env PGPORT=5433 psql -c "ALTER ROLE seoauto_staging WITH LOGIN PASSWORD '${DB_PASS}';" 
sudo -u postgres env PGPORT=5433 psql -d seoauto_staging -c "GRANT ALL ON SCHEMA public TO seoauto_staging; ALTER SCHEMA public OWNER TO seoauto_staging;"
PGPASSWORD="$DB_PASS" psql -h 127.0.0.1 -p 5433 -U seoauto_staging -d seoauto_staging -c 'SELECT current_user;'

# Pull latest fix d3aa9e8
sudo -u seoauto_vn_usr git -C "$STAGING_ROOT" fetch origin feature/seo-helper-release-cicd
sudo -u seoauto_vn_usr git -C "$STAGING_ROOT" checkout -f origin/feature/seo-helper-release-cicd
echo "NOW=$(sudo -u seoauto_vn_usr git -C $STAGING_ROOT rev-parse --short HEAD)"

systemctl restart digiseo-staging.service
sleep 25
systemctl is-active digiseo-staging.service || { journalctl -u digiseo-staging -n 60 --no-pager; exit 1; }
ss -ltnp | grep 8901
curl -sS -m 10 -o /dev/null -w "docs %{http_code}\n" http://127.0.0.1:8901/docs
# migrate
sudo -u seoauto_vn_usr bash -lc 'cd /var/www/seoauto_vn_usr/data/www-staging && set -a && source env.local && set +a && .venv/bin/python - <<"PY"
from app.migrations.ensure_wordpress_plugin_tables import ensure_wordpress_plugin_tables
print(ensure_wordpress_plugin_tables())
PY'
systemctl is-active digiseo.service
echo STAGING_API_OK
