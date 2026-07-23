from pathlib import Path

ROOT = Path(r"d:\App\SEOAuto SEO Helper")
OLD, NEW = "1.2.0-rc.1", "1.2.0-rc.2"


def bump(rel: str) -> None:
    p = ROOT / rel
    text = p.read_text(encoding="utf-8")
    if OLD not in text:
        raise SystemExit(f"{rel}: missing {OLD}")
    p.write_bytes(text.replace(OLD, NEW).encode("utf-8"))
    print(rel, "ok")


bump("seoauto-seo-helper.php")
p = ROOT / "readme.txt"
text = p.read_text(encoding="utf-8")
text2 = text.replace("Stable tag: 1.2.0-rc.1", "Stable tag: 1.2.0-rc.2", 1)
if "= 1.2.0-rc.1 =" in text2:
    text2 = text2.replace(
        "= 1.2.0-rc.1 =\n* Release candidate for ContentOps Phase 2.\n\n",
        "= 1.2.0-rc.2 =\n* Release candidate (CI-clean): DB_VERSION test aligned to 4.\n\n= 1.2.0-rc.1 =\n* Release candidate for ContentOps Phase 2 (tag kept; CI QA blocked on stale DB_VERSION assert).\n\n",
        1,
    )
p.write_bytes(text2.encode("utf-8"))
print("readme.txt ok")
print("done")
