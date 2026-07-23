#!/usr/bin/env python3
"""Build dated bridge 1.0.5 artifact (never overwrite previous dated folders).

Outputs under artifacts/bridge-1.0.5/<stamp>/ :
  - seoauto-seo-helper-1.0.5-bridge.zip
  - seoauto-seo-helper-1.0.5-bridge.zip.sha256
  - VERIFY.txt (structure + version checks, no secrets)
"""
from __future__ import annotations

import hashlib
import re
import shutil
import subprocess
import zipfile
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(r"d:\App\SEOAuto SEO Helper")
VERSION = "1.0.5"
TAG_BASE = "v1.0.4"
STAMP = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
OUT_DIR = ROOT / "artifacts" / "bridge-1.0.5" / STAMP
ZIP_NAME = "seoauto-seo-helper-1.0.5-bridge.zip"


def git_show(path: str) -> bytes:
    return subprocess.check_output(["git", "show", f"{TAG_BASE}:{path}"], cwd=ROOT)


def git_list() -> list[str]:
    out = subprocess.check_output(["git", "ls-tree", "-r", "--name-only", TAG_BASE], cwd=ROOT)
    return [ln.decode("utf-8") for ln in out.splitlines() if ln.strip()]


def patch_main(php: str) -> str:
    if "Update URI:" not in php:
        php = php.replace(
            " * Domain Path:       /languages\n",
            " * Domain Path:       /languages\n"
            " * Update URI:        https://seoauto.vn/plugin/seoauto-seo-helper\n",
            1,
        )
    php = re.sub(r"Version:\s+\S+", f"Version:           {VERSION}", php, count=1)
    php = re.sub(
        r"define\(\s*'SEOAUTO_HELPER_VERSION',\s*'[^']+'\s*\)",
        f"define( 'SEOAUTO_HELPER_VERSION', '{VERSION}' )",
        php,
        count=1,
    )
    return php


def patch_plugin(php: str) -> str:
    if "Update_Manager" in php:
        return php
    uses = (
        "use SEOAuto\\SEOHelper\\Updater\\Update_Admin;\n"
        "use SEOAuto\\SEOHelper\\Updater\\Update_Manager;\n"
    )
    php = php.replace(
        "use SEOAuto\\SEOHelper\\Seo\\Seo_Facade;\n",
        "use SEOAuto\\SEOHelper\\Seo\\Seo_Facade;\n" + uses,
        1,
    )
    php = php.replace(
        "\tprivate Cron_Scheduler $cron;\n",
        "\tprivate Cron_Scheduler $cron;\n\tprivate Update_Manager $updater;\n",
        1,
    )
    php = php.replace(
        "\t\t$this->cron        = new Cron_Scheduler( $this->connection, $this->entitlement, $this->audit );\n\t}\n",
        "\t\t$this->cron        = new Cron_Scheduler( $this->connection, $this->entitlement, $this->audit );\n"
        "\t\t$this->updater     = new Update_Manager(\n"
        "\t\t\t$this->connection,\n"
        "\t\t\t$this->entitlement,\n"
        "\t\t\t$this->audit\n"
        "\t\t);\n\t}\n",
        1,
    )
    php = php.replace(
        "\t\t$this->seo->register_hooks();\n\t\t$this->cron->register();\n\n\t\t\\SEOAuto\\SEOHelper\\Post\\Schema::maybe_upgrade();\n",
        "\t\t$this->seo->register_hooks();\n"
        "\t\t$this->cron->register();\n"
        "\t\t$this->updater->register();\n"
        "\t\t( new Update_Admin( $this->updater ) )->register();\n\n"
        "\t\t\\SEOAuto\\SEOHelper\\Post\\Schema::maybe_upgrade();\n",
        1,
    )
    if "function updater(" not in php:
        php = php.replace(
            "\tpublic function connection(): Connection_Manager {\n\t\treturn $this->connection;\n\t}\n",
            "\tpublic function connection(): Connection_Manager {\n\t\treturn $this->connection;\n\t}\n\n"
            "\tpublic function updater(): Update_Manager {\n\t\treturn $this->updater;\n\t}\n",
            1,
        )
    return php


def patch_readme(txt: str) -> str:
    return re.sub(r"(?m)^Stable tag:\s*\S+", f"Stable tag: {VERSION}", txt, count=1)


def skip(name: str) -> bool:
    n = name.replace("\\", "/")
    skip_exact = (".gitignore", ".gitattributes", ".editorconfig")
    base = n.rsplit("/", 1)[-1]
    if base in skip_exact:
        return True
    bad = (
        ".env",
        "/.git/",
        "phpunit",
        "composer.",
        "/tests/",
        "/vendor/",
        "/node_modules/",
        "/logs/",
        "/cache/",
        "SeoAudit/",
        "/scripts/",
        "/artifacts/",
    )
    return any(b in n for b in bad)


def forbidden(name: str) -> bool:
    """Paths that must never appear in a customer ZIP."""
    n = name.replace("\\", "/")
    critical = (".env", "/.git/", "SeoAudit/", "/tests/", "/vendor/", "phpunit", "composer.")
    return any(b in n for b in critical)


def main() -> None:
    if OUT_DIR.exists():
        raise SystemExit(f"refusing overwrite {OUT_DIR}")
    OUT_DIR.mkdir(parents=True)
    stage = OUT_DIR / "_stage" / "seoauto-seo-helper"
    stage.mkdir(parents=True)

    for rel in git_list():
        if rel.startswith((".github/", "tests/", "scripts/", "artifacts/")):
            continue
        dest = stage / rel
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(git_show(rel))

    upd_src = ROOT / "includes" / "Updater"
    upd_dst = stage / "includes" / "Updater"
    if upd_dst.exists():
        shutil.rmtree(upd_dst)
    shutil.copytree(upd_src, upd_dst)

    main_php = patch_main((stage / "seoauto-seo-helper.php").read_text(encoding="utf-8"))
    (stage / "seoauto-seo-helper.php").write_text(main_php, encoding="utf-8", newline="\n")
    plugin_php = patch_plugin((stage / "includes" / "Plugin.php").read_text(encoding="utf-8"))
    if "Update_Manager" not in plugin_php:
        raise SystemExit("Update_Manager not wired")
    (stage / "includes" / "Plugin.php").write_text(plugin_php, encoding="utf-8", newline="\n")
    readme = stage / "readme.txt"
    if readme.exists():
        readme.write_text(patch_readme(readme.read_text(encoding="utf-8")), encoding="utf-8", newline="\n")

    zip_path = OUT_DIR / ZIP_NAME
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as z:
        for f in stage.rglob("*"):
            if not f.is_file():
                continue
            arc = f"seoauto-seo-helper/{f.relative_to(stage).as_posix()}"
            if skip(arc):
                continue
            if forbidden(arc):
                raise SystemExit(f"forbidden path in zip: {arc}")
            z.write(f, arc)

    with zipfile.ZipFile(zip_path) as z:
        names = z.namelist()
        assert "seoauto-seo-helper/seoauto-seo-helper.php" in names
        assert any("includes/Updater/" in n for n in names)
        assert not any("SeoAudit" in n for n in names)
        assert not any(forbidden(n) for n in names)
        roots = {n.split("/")[0] for n in names if n}
        assert roots == {"seoauto-seo-helper"}
        assert z.testzip() is None
        header = z.read("seoauto-seo-helper/seoauto-seo-helper.php").decode("utf-8")
        assert f"Version:           {VERSION}" in header
        assert f"SEOAUTO_HELPER_VERSION', '{VERSION}'" in header or f'SEOAUTO_HELPER_VERSION", "{VERSION}"' in header
        assert "Update URI:" in header

    sha = hashlib.sha256(zip_path.read_bytes()).hexdigest()
    (OUT_DIR / f"{ZIP_NAME}.sha256").write_text(sha + "\n", encoding="utf-8")

    # Optional local HMAC note (signature for release registry uses CI signing key — not embedded in ZIP)
    verify = [
        f"version={VERSION}",
        f"stamp={STAMP}",
        f"zip={ZIP_NAME}",
        f"sha256={sha}",
        f"files={len(names)}",
        "updater=present",
        "seoaudit=absent",
        "secrets_paths=absent",
        "single_root=seoauto-seo-helper",
        "note=ZIP itself is not HMAC-signed; release signature is applied by SaaS CI when publishing.",
    ]
    (OUT_DIR / "VERIFY.txt").write_text("\n".join(verify) + "\n", encoding="utf-8")

    shutil.rmtree(OUT_DIR / "_stage")
    # latest pointer (text only, safe to overwrite)
    latest = ROOT / "artifacts" / "bridge-1.0.5" / "LATEST.txt"
    latest.write_text(f"{STAMP}\n{zip_path.as_posix()}\n{sha}\n", encoding="utf-8")
    print("ARTIFACT", zip_path)
    print("SHA256", sha)
    print("DIR", OUT_DIR)


if __name__ == "__main__":
    main()
