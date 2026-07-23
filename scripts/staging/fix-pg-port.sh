#!/usr/bin/env bash
set -euo pipefail
STAGING_ROOT=/var/www/seoauto_vn_usr/data/www-staging
DB_PASS=$(tr -d '\r\n' < "$STAGING_ROOT/data/staging_db.pass")
# Host PG16 listens on 5433; 5432 is docker-proxy
PGPORT=5433
export PGHOST=127.0.0.1
export PGPORT

sudo -u postgres env PGPORT=$PGPORT psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'seoauto_staging') THEN
    CREATE ROLE seoauto_staging LOGIN PASSWORD '${DB_PASS}';
  ELSE
    ALTER ROLE seoauto_staging WITH LOGIN PASSWORD '${DB_PASS}';
  END IF;
END
\$\$;
SQL
if ! sudo -u postgres env PGPORT=$PGPORT psql -tAc "SELECT 1 FROM pg_database WHERE datname='seoauto_staging'" | grep -q 1; then
  sudo -u postgres env PGPORT=$PGPORT createdb -O seoauto_staging seoauto_staging
fi
sudo -u postgres env PGPORT=$PGPORT psql -d seoauto_staging -v ON_ERROR_STOP=1 -c "GRANT ALL ON SCHEMA public TO seoauto_staging; ALTER SCHEMA public OWNER TO seoauto_staging;"
PGPASSWORD="$DB_PASS" psql -h 127.0.0.1 -p $PGPORT -U seoauto_staging -d seoauto_staging -c 'SELECT current_user, current_database();'

# Rewrite DATABASE_URL port to 5433
python3 - <<'PY'
from pathlib import Path
import re
p = Path("/var/www/seoauto_vn_usr/data/www-staging/env.local")
text = p.read_text()
text2 = re.sub(r"(DATABASE_URL=postgresql\+psycopg2://seoauto_staging:[^@]+@127\.0\.0\.1:)\d+(/seoauto_staging)",
               r"\g<1>5433\g<2>", text)
p.write_text(text2)
print("DATABASE_URL port fixed to 5433")
PY
grep '^DATABASE_URL=' "$STAGING_ROOT/env.local" | sed -E 's#(://)[^:]+:[^@]+@#\1***:***@#'
chmod 600 "$STAGING_ROOT/env.local"
chown seoauto_vn_usr:seoauto_vn_usr "$STAGING_ROOT/env.local"

systemctl restart digiseo-staging.service
sleep 15
systemctl is-active digiseo-staging.service || { journalctl -u digiseo-staging -n 40 --no-pager; exit 1; }
curl -sS -o /tmp/stg_health.txt -w "HTTP %{http_code}\n" http://127.0.0.1:8901/docs
head -c 100 /tmp/stg_health.txt; echo
sudo -u seoauto_vn_usr bash -lc 'cd /var/www/seoauto_vn_usr/data/www-staging && set -a && source env.local && set +a && .venv/bin/python - <<"PY"
from app.migrations.ensure_wordpress_plugin_tables import ensure_wordpress_plugin_tables
print(ensure_wordpress_plugin_tables())
PY'
systemctl is-active digiseo.service
echo FIXED_PORT_5433
