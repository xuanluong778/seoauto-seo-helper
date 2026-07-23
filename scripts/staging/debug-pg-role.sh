#!/usr/bin/env bash
set -euo pipefail
echo "== clusters =="
pg_lsclusters || true
echo "== roles =="
sudo -u postgres psql -c "\du seoauto*"
echo "== who listens 5432 =="
ss -ltnp | grep 5432 || true
echo "== create role explicit =="
DB_PASS=$(cat /var/www/seoauto_vn_usr/data/www-staging/data/staging_db.pass)
sudo -u postgres psql -c "DROP ROLE IF EXISTS seoauto_staging;"
sudo -u postgres psql -c "CREATE ROLE seoauto_staging LOGIN PASSWORD '${DB_PASS}';"
sudo -u postgres psql -c "\du seoauto_staging"
sudo -u postgres psql -c "ALTER DATABASE seoauto_staging OWNER TO seoauto_staging;"
sudo -u postgres psql -d seoauto_staging -c "GRANT ALL ON SCHEMA public TO seoauto_staging; ALTER SCHEMA public OWNER TO seoauto_staging;"
echo "== tcp login =="
PGPASSWORD="$DB_PASS" psql -h 127.0.0.1 -U seoauto_staging -d seoauto_staging -c 'SELECT current_user;'
echo OK
