#!/usr/bin/env bash
# R2 staging smoke without printing secrets. Restarts digiseo-staging only.
set -euo pipefail
ENV=/var/www/seoauto_vn_usr/data/www-staging/env.local

PYBIN=/var/www/seoauto_vn_usr/data/www/.venv/bin/python3
"$PYBIN" - <<'PY'
import hashlib, os, sys, time, urllib.request
from pathlib import Path

def load(p):
    kv={}
    for ln in Path(p).read_text(encoding="utf-8").splitlines():
        if "=" in ln and not ln.strip().startswith("#"):
            k,v=ln.split("=",1); kv[k.strip()]=v.strip()
    return kv

kv=load("/var/www/seoauto_vn_usr/data/www-staging/env.local")
os.environ.update({k:kv[k] for k in kv})
os.environ.pop("R2_FORCE_PATH_STYLE", None)
leaked="d360dc3836eb0b45ba5661c49860a4f7"
cur=kv.get("R2_ACCESS_KEY_ID","")
print("rotated=", "YES" if cur and cur!=leaked else ("NO" if cur==leaked else "UNKNOWN"))
print("backend=", kv.get("WP_PLUGIN_STORAGE_BACKEND"))
print("bucket=", kv.get("R2_BUCKET"))
ep=kv.get("R2_ENDPOINT_URL","")
print("endpoint_host=", ep.split("//",1)[-1].split("/")[0] if ep else "")
print("ak_sha12=", hashlib.sha256(cur.encode()).hexdigest()[:12] if cur else "missing")

sys.path.insert(0, "/var/www/seoauto_vn_usr/data/www-staging")
from app.services.storage_adapter import get_storage_adapter
ad=get_storage_adapter()
print("adapter=", type(ad).__name__)
key=f"_smoke/rc-prep-{int(time.time())}.txt"
payload=b"r2-staging-smoke-rotated"
ad.put_bytes(key, payload)
assert ad.exists(key)
assert ad.get_bytes(key)==payload
url=ad.presign_get(key, expires_in=120)
assert url and url.startswith("http")
with urllib.request.urlopen(url, timeout=30) as resp:
    assert resp.read()==payload
print("smoke_put_get_head_signed_ok")
assert ad.exists("missing/no-such-e2e-object.zip") is False
print("smoke_missing_ok")
ad.delete(key)
print("R2_SMOKE_PASS")
PY

systemctl restart digiseo-staging.service
# never restart digiseo production
sleep 6
curl -sk -o /dev/null -w "api %{http_code}\n" https://staging.seoauto.vn/docs
systemctl is-active digiseo-staging
systemctl is-active digiseo
echo "prod_not_restarted_ok"
