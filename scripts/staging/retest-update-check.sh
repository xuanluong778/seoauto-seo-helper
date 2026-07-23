#!/usr/bin/env bash
set -euo pipefail
curl -sk -o /dev/null -w "api %{http_code}\n" https://staging.seoauto.vn/docs
WP1=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
WP2=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper2-staging.siteauto.vn
FAIL=0
for WP in "$WP1" "$WP2"; do
  echo "== $WP =="
  if ! wp eval '
$u=SEOAuto\SEOHelper\Plugin::instance()->updater();
$r=$u->force_check();
if (is_wp_error($r)) { echo "FAIL ", $r->get_error_code(), " ", $r->get_error_message(), "\n"; exit(1);} 
echo "ok available=", !empty($r->update_available)?"1":"0", " ver=", $r->version, "\n";
' --path="$WP" --allow-root; then
    FAIL=1
  fi
done
if [[ "$FAIL" -eq 0 ]]; then echo UPDATE_CHECK_RETEST_PASS; else echo UPDATE_CHECK_RETEST_FAIL; exit 1; fi
