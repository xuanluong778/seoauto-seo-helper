#!/usr/bin/env bash
set -euo pipefail
sudo -u seoauto_vn_usr bash -lc 'cd /var/www/seoauto_vn_usr/data/www-staging && set -a && source env.local && set +a && .venv/bin/python - <<"PY"
import secrets
from app.db import SessionLocal, Base, engine
from app.migrations.ensure_wordpress_plugin_tables import ensure_wordpress_plugin_tables
from app.core.security import hash_password
from app.services.wordpress_pairing_service import create_pairing_code

# Import model graph so User relationships resolve
import app.models.user  # noqa
import app.models.seo  # noqa — Project relationship
import app.models.knowledge  # noqa
from app.models.user import User

ensure_wordpress_plugin_tables()
Base.metadata.create_all(bind=engine)
db = SessionLocal()
try:
    email = "staging-helper@seoauto.vn"
    user = db.query(User).filter(User.email == email).first()
    if user is None:
        user = User(
            email=email,
            password_hash=hash_password("StagingHelper!" + secrets.token_hex(4)),
            role="admin",
            status="active",
            credit_balance=1000,
        )
        db.add(user)
        db.commit()
        db.refresh(user)
        print({"user_created": True, "user_id": int(user.id)})
    else:
        user.role = "admin"
        db.commit()
        print({"user_created": False, "user_id": int(user.id)})
    out = create_pairing_code(db, user, domain_hint="seohelper-staging.siteauto.vn")
    print({"code": out["code"], "expires_at": out["expires_at"], "pairing_code_id": out["pairing_code_id"]})
finally:
    db.close()
PY'
echo SEED_DONE
