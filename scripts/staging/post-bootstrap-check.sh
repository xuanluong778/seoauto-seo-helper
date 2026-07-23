#!/usr/bin/env bash
set -euo pipefail
sleep 5
ss -ltnp | grep 8901 || true
curl -sS -o /tmp/stg_health.txt -w "HTTP %{http_code}\n" http://127.0.0.1:8901/docs || true
head -c 120 /tmp/stg_health.txt; echo
sudo -u seoauto_vn_usr bash -lc 'cd /var/www/seoauto_vn_usr/data/www-staging && set -a && source env.local && set +a && .venv/bin/python - <<"PY"
from app.migrations.ensure_wordpress_plugin_tables import ensure_wordpress_plugin_tables
print(ensure_wordpress_plugin_tables())
PY'
systemctl is-active digiseo.service
systemctl is-active digiseo-staging.service
echo OK_MIGRATE
