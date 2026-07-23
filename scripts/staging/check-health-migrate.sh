#!/usr/bin/env bash
set -euo pipefail
echo "prod=$(systemctl is-active digiseo.service) staging=$(systemctl is-active digiseo-staging.service)"
ss -ltnp | grep 8901 || true
curl -sS -m 10 -o /dev/null -w "docs %{http_code}\n" http://127.0.0.1:8901/docs
# migrate release tables
sudo -u seoauto_vn_usr bash -lc 'cd /var/www/seoauto_vn_usr/data/www-staging && set -a && source env.local && set +a && .venv/bin/python - <<"PY"
from app.migrations.ensure_wordpress_plugin_tables import ensure_wordpress_plugin_tables
print(ensure_wordpress_plugin_tables())
PY'
# sync release service fix already at d3aa9e8
cd /var/www/seoauto_vn_usr/data/www-staging
echo "HEAD=$(git rev-parse --short HEAD)"
# check DNS
dig +short A staging.seoauto.vn @8.8.8.8 || true
dig +short A seohelper-staging.siteauto.vn @8.8.8.8 || true
dig +short NS seoauto.vn @8.8.8.8 || true
dig +short NS siteauto.vn @8.8.8.8 || true
# mysql?
command -v mysql; mysql --version || true
echo CHECK_DONE
