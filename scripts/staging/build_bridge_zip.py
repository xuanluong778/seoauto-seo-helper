import zipfile, shutil, pathlib, re, tempfile, os

root = pathlib.Path(r"d:\App\SEOAuto SEO Helper")
stage = pathlib.Path(tempfile.mkdtemp()) / "seoauto-seo-helper"
stage.mkdir(parents=True)
for name in [
    "seoauto-seo-helper.php",
    "uninstall.php",
    "readme.txt",
    "README.md",
    "includes",
    "assets",
    "docs",
]:
    src = root / name
    if src.exists():
        dest = stage / name
        if src.is_dir():
            shutil.copytree(src, dest)
        else:
            shutil.copy2(src, dest)

main_path = stage / "seoauto-seo-helper.php"
main = main_path.read_text(encoding="utf-8")
main = re.sub(r"Version:\s+\S+", "Version:           1.0.4", main, count=1)
main = re.sub(
    r"define\(\s*'SEOAUTO_HELPER_VERSION',\s*'[^']+'\s*\)",
    "define( 'SEOAUTO_HELPER_VERSION', '1.0.4' )",
    main,
    count=1,
)
main_path.write_text(main, encoding="utf-8")

out = pathlib.Path(os.environ["TEMP"]) / "seoauto-seo-helper-1.0.4-bridge.zip"
if out.exists():
    out.unlink()
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for f in stage.rglob("*"):
        if f.is_file():
            z.write(f, "seoauto-seo-helper/" + f.relative_to(stage).as_posix())

with zipfile.ZipFile(out) as z:
    names = z.namelist()
    assert "seoauto-seo-helper/seoauto-seo-helper.php" in names, names[:10]
    print("ok", out, "count", len(names))
