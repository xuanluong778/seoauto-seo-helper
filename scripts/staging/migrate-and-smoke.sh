#!/usr/bin/env bash
set -euo pipefail
systemctl is-active digiseo.service
systemctl is-active digiseo-staging.service
curl -sS -m 5 -o /dev/null -w "docs %{http_code}\n" http://127.0.0.1:8901/docs
sudo -u seoauto_vn_usr bash -lc 'cd /var/www/seoauto_vn_usr/data/www-staging && set -a && source env.local && set +a && .venv/bin/python - <<"PY"
from app.migrations.ensure_wordpress_plugin_tables import ensure_wordpress_plugin_tables
print(ensure_wordpress_plugin_tables())
PY'
# smoke release create with local storage (no secrets printed)
sudo -u seoauto_vn_usr bash -lc 'cd /var/www/seoauto_vn_usr/data/www-staging && set -a && source env.local && set +a && .venv/bin/python - <<"PY"
from pathlib import Path
from app.db import SessionLocal
from app.services.storage_adapter import LocalStorageAdapter, build_release_storage_key
from app.services.wordpress_plugin_release_service import create_release, release_to_dict, sign_package_release
import hashlib, os
root = Path(os.environ["WP_PLUGIN_RELEASE_STORAGE"])
key = build_release_storage_key(slug="seoauto-seo-helper", channel="beta", version="1.1.0-beta.1")
data = b"PK\x03\x04smoke"
store = LocalStorageAdapter(root)
store.put_bytes(key, data)
sha = hashlib.sha256(data).hexdigest()
sig = sign_package_release(version="1.1.0-beta.1", sha256=sha, channel="beta")
db = SessionLocal()
try:
    row = create_release(db, version="1.1.0-beta.1", channel="beta", storage_key=key, sha256=sha, signature=sig, verify_object_exists=True)
    d = release_to_dict(row)
    print({"ok": True, "status": d["status"], "version": d["version"], "channel": d["channel"], "sha256": d["sha256"][:12]+"…"})
finally:
    db.close()
PY'
echo MIGRATE_AND_SMOKE_OK
