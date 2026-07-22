#!/usr/bin/env python3
"""Build release-manifest.json + package signature for CI."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path


def sign(version: str, sha256: str, channel: str, key: str) -> str:
    payload = f"{version}|{sha256.lower()}|{channel}"
    return hmac.new(key.encode("utf-8"), payload.encode("utf-8"), hashlib.sha256).hexdigest()


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--zip", required=True)
    p.add_argument("--version", required=True)
    p.add_argument("--channel", default="stable")
    p.add_argument("--requires-wp", default="6.0")
    p.add_argument("--requires-php", default="8.1")
    p.add_argument("--tested-wp", default="6.7")
    p.add_argument("--changelog-url", default="")
    p.add_argument("--out", default="release-manifest.json")
    args = p.parse_args()

    zip_path = Path(args.zip)
    if not zip_path.is_file():
        print(f"ZIP missing: {zip_path}", file=sys.stderr)
        return 1

    sha = hashlib.sha256(zip_path.read_bytes()).hexdigest()
    key = (os.environ.get("WP_PLUGIN_RELEASE_SIGNING_KEY") or "").strip()
    if not key:
        print("WP_PLUGIN_RELEASE_SIGNING_KEY is required", file=sys.stderr)
        return 1

    version = args.version.lstrip("vV")
    channel = args.channel
    signature = sign(version, sha, channel, key)
    storage_key = f"seoauto-seo-helper/{channel}/{version}/seoauto-seo-helper-{version}.zip"

    manifest = {
        "slug": "seoauto-seo-helper",
        "version": version,
        "channel": channel,
        "sha256": sha,
        "signature": signature,
        "storage_key": storage_key,
        "requires_wp": args.requires_wp,
        "requires_php": args.requires_php,
        "tested_wp": args.tested_wp,
        "changelog_url": args.changelog_url,
        "built_at": datetime.now(timezone.utc).isoformat(),
        "zip_name": zip_path.name,
        "zip_bytes": zip_path.stat().st_size,
    }
    Path(args.out).write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    Path(str(args.out) + ".sha256").write_text(sha, encoding="utf-8")
    print(json.dumps(manifest, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
