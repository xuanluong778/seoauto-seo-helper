#!/usr/bin/env bash
set -euo pipefail
STAGING_ROOT=/var/www/seoauto_vn_usr/data/www-staging
DB_PASS=$(cat "$STAGING_ROOT/data/staging_db.pass")
sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'seoauto_staging') THEN
    CREATE ROLE seoauto_staging LOGIN PASSWORD '${DB_PASS}';
  ELSE
    ALTER ROLE seoauto_staging WITH LOGIN PASSWORD '${DB_PASS}';
  END IF;
END
\$\$;
SELECT 1 FROM pg_database WHERE datname='seoauto_staging';
SQL
# ensure DB exists
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='seoauto_staging'" | grep -q 1; then
  sudo -u postgres createdb -O seoauto_staging seoauto_staging
fi
sudo -u postgres psql -v ON_ERROR_STOP=1 -c "GRANT ALL PRIVILEGES ON DATABASE seoauto_staging TO seoauto_staging;"
sudo -u postgres psql -d seoauto_staging -v ON_ERROR_STOP=1 -c "GRANT ALL ON SCHEMA public TO seoauto_staging; ALTER SCHEMA public OWNER TO seoauto_staging;"
# verify login
PGPASSWORD="$DB_PASS" psql -h 127.0.0.1 -U seoauto_staging -d seoauto_staging -c 'SELECT current_user, current_database();'
# show DATABASE_URL user (no password)
grep '^DATABASE_URL=' "$STAGING_ROOT/env.local" | sed -E 's#(postgresql[^:]+://)[^:]+:[^@]+@#\1***:***@#'
systemctl restart digiseo-staging.service
sleep 12
systemctl is-active digiseo-staging.service || { journalctl -u digiseo-staging -n 30 --no-pager; exit 1; }
curl -sS -o /tmp/stg_health.txt -w "HTTP %{http_code}\n" http://127.0.0.1:8901/docs
head -c 80 /tmp/stg_health.txt; echo
sudo -u seoauto_vn_usr bash -lc 'cd /var/www/seoauto_vn_usr/data/www-staging && set -a && source env.local && set +a && .venv/bin/python - <<"PY"
from app.migrations.ensure_wordpress_plugin_tables import ensure_wordpress_plugin_tables
print(ensure_wordpress_plugin_tables())
PY'
systemctl is-active digiseo.service
echo FIXED_DB
