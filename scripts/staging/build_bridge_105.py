#!/usr/bin/env python3
"""Build seoauto-seo-helper 1.0.5 bridge ZIP: v1.0.4 base + Private Updater only.

Does not include SeoAudit / Phase 2. Does not alter Billing/Credit/HMAC modules
beyond wiring Update_Manager into Plugin::boot().
"""
from __future__ import annotations

import re
import shutil
import subprocess
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(r"d:\App\SEOAuto SEO Helper")
OUT = Path(tempfile.gettempdir()) / "seoauto-seo-helper-1.0.5-bridge.zip"
VERSION = "1.0.5"
TAG_BASE = "v1.0.4"


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


def main() -> None:
    stage = Path(tempfile.mkdtemp()) / "seoauto-seo-helper"
    stage.mkdir(parents=True)

    for rel in git_list():
        # Skip tests / CI / docs noise if present on tag
        if rel.startswith(".github/") or rel.startswith("tests/") or rel.startswith("scripts/"):
            continue
        dest = stage / rel
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(git_show(rel))

    # Copy Updater from current working tree (feature)
    upd_src = ROOT / "includes" / "Updater"
    upd_dst = stage / "includes" / "Updater"
    if upd_dst.exists():
        shutil.rmtree(upd_dst)
    shutil.copytree(upd_src, upd_dst)

    main_php = patch_main((stage / "seoauto-seo-helper.php").read_text(encoding="utf-8"))
    (stage / "seoauto-seo-helper.php").write_text(main_php, encoding="utf-8", newline="\n")

    plugin_php = patch_plugin((stage / "includes" / "Plugin.php").read_text(encoding="utf-8"))
    if "Update_Manager" not in plugin_php:
        raise SystemExit("Failed to wire Update_Manager into Plugin.php — inspect v1.0.4 structure")
    (stage / "includes" / "Plugin.php").write_text(plugin_php, encoding="utf-8", newline="\n")

    readme = stage / "readme.txt"
    if readme.exists():
        readme.write_text(patch_readme(readme.read_text(encoding="utf-8")), encoding="utf-8", newline="\n")

    if OUT.exists():
        OUT.unlink()
    with zipfile.ZipFile(OUT, "w", zipfile.ZIP_DEFLATED) as z:
        for f in stage.rglob("*"):
            if f.is_file():
                z.write(f, f"seoauto-seo-helper/{f.relative_to(stage).as_posix()}")

    with zipfile.ZipFile(OUT) as z:
        names = z.namelist()
        assert "seoauto-seo-helper/seoauto-seo-helper.php" in names
        assert any(n.startswith("seoauto-seo-helper/includes/Updater/") for n in names)
        assert not any("/SeoAudit/" in n for n in names), "bridge must not ship SeoAudit"
        roots = {n.split("/")[0] for n in names if n}
        assert roots == {"seoauto-seo-helper"}
        print("ok", OUT, "files", len(names), "version", VERSION)


if __name__ == "__main__":
    main()
