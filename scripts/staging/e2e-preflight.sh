#!/usr/bin/env bash
set -euo pipefail
WP=/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn
STAGING=/var/www/seoauto_vn_usr/data/www-staging
echo "=== ENV KEYS (names only) ==="
cut -d= -f1 "$STAGING/env.local" | grep -E '^(SEOAUTO_ENV|APP_BASE_URL|PUBLIC_BASE_URL|DATABASE_URL|REDIS_URL|WP_PLUGIN_|R2_)' | sort
echo "=== STORAGE BACKEND ==="
grep '^WP_PLUGIN_STORAGE_BACKEND=' "$STAGING/env.local" | cut -d= -f2 || echo missing
echo "=== R2_BUCKET set? ==="
grep -q '^R2_BUCKET=.' "$STAGING/env.local" && echo yes || echo no
echo "=== CI TOKEN set? ==="
grep -q '^WP_PLUGIN_CI_RELEASE_TOKEN=.' "$STAGING/env.local" && echo yes || echo no
echo "=== SIGNING KEY set? ==="
grep -q '^WP_PLUGIN_RELEASE_SIGNING_KEY=.' "$STAGING/env.local" && echo yes || echo no
echo "=== PUBLIC BASE ==="
grep '^PUBLIC_BASE_URL=' "$STAGING/env.local" | sed 's/=.*/=***/'
echo "=== SAAS HEAD ==="
git -C "$STAGING" rev-parse --short HEAD
echo "=== MIGRATE ==="
sudo -u seoauto_vn_usr bash -lc "cd '$STAGING' && set -a && source env.local && set +a && .venv/bin/python -c 'from app.migrations.ensure_wordpress_plugin_tables import ensure_wordpress_plugin_tables as e; print(e())'"
echo "=== WP PLUGIN ==="
wp plugin get seoauto-seo-helper --fields=name,status,version --path="$WP" --allow-root
wp eval-file - --path="$WP" --allow-root <<'PHP'
<?php
$c = SEOAuto\SEOHelper\Plugin::instance()->connection();
echo 'status=' . $c->option('status', '') . "\n";
echo 'api=' . $c->api_base() . "\n";
echo 'site_id=' . $c->option('site_id', '') . "\n";
echo 'connection_id=' . $c->option('connection_id', '') . "\n";
$posts = wp_count_posts('post');
echo 'posts_publish=' . (int) $posts->publish . "\n";
$media = wp_count_posts('attachment');
echo 'media=' . (int) $media->inherit . "\n";
echo 'has_updater=' . (class_exists('SEOAuto\\SEOHelper\\Updater\\Update_Manager') ? 'yes' : 'no') . "\n";
echo 'version_const=' . SEOAUTO_HELPER_VERSION . "\n";
PHP
curl -sS -m 8 -o /dev/null -w "api %{http_code}\n" https://staging.seoauto.vn/docs
curl -sS -m 8 -o /dev/null -w "wp %{http_code}\n" https://wp-staging.seoauto.vn/
systemctl is-active digiseo-staging
echo PREFLIGHT_OK
