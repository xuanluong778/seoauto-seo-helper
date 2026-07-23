#!/usr/bin/env bash
# Add resolving alias for staging API until staging.seoauto.vn DNS exists.
# Does not touch production digiseo.service.
set -euo pipefail
DOMAIN=seoauto-api-staging.siteauto.vn
IP=116.118.45.72
# Append server_name alias to existing staging nginx
python3 - <<'PY'
from pathlib import Path
p = Path('/etc/nginx/conf.d/staging.seoauto.vn.conf')
text = p.read_text()
if 'seoauto-api-staging.siteauto.vn' not in text:
    text = text.replace('server_name staging.seoauto.vn;', 'server_name staging.seoauto.vn seoauto-api-staging.siteauto.vn;')
    p.write_text(text)
    print('nginx alias added')
else:
    print('nginx alias already present')
PY
nginx -t && systemctl reload nginx
# Point PUBLIC_BASE_URL to resolving alias for WP/HMAC package URLs
python3 - <<'PY'
from pathlib import Path
p = Path('/var/www/seoauto_vn_usr/data/www-staging/env.local')
lines = []
for line in p.read_text().splitlines():
    if line.startswith('APP_BASE_URL=') or line.startswith('PUBLIC_BASE_URL='):
        key = line.split('=',1)[0]
        lines.append(f'{key}=https://seoauto-api-staging.siteauto.vn')
    else:
        lines.append(line)
p.write_text('\n'.join(lines)+'\n')
print('PUBLIC_BASE updated to seoauto-api-staging.siteauto.vn')
PY
# Keep secrets file in sync (names only printed)
grep -E '^(SEOAUTO_API_BASE|APP_BASE_URL|PUBLIC_BASE_URL)=' /var/www/seoauto_vn_usr/data/www-staging/env.local
sed -i 's#^SEOAUTO_API_BASE=.*#SEOAUTO_API_BASE=https://seoauto-api-staging.siteauto.vn#' /var/www/seoauto_vn_usr/data/www-staging/data/staging_ci_secrets.env || true
chmod 600 /var/www/seoauto_vn_usr/data/www-staging/env.local /var/www/seoauto_vn_usr/data/www-staging/data/staging_ci_secrets.env
chown seoauto_vn_usr:seoauto_vn_usr /var/www/seoauto_vn_usr/data/www-staging/env.local /var/www/seoauto_vn_usr/data/www-staging/data/staging_ci_secrets.env
systemctl restart digiseo-staging
sleep 15
curl -sk -m 10 -o /dev/null -w "alias_https %{http_code}\n" https://seoauto-api-staging.siteauto.vn/docs
systemctl is-active digiseo digiseo-staging
echo ALIAS_OK
