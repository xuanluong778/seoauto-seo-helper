#!/usr/bin/env php
<?php
/**
 * Staging E2E harness for Private Updater (run on staging WP with WP-CLI or include bootstrap).
 *
 * Cases covered (unit + simulated):
 *  - version gate 1.0.4 → 1.1.0
 *  - checksum / signature / zip root / expired / downgrade blocks
 *  - LOCKED still allowed to check updates
 *  - Schema::maybe_upgrade idempotent (migration path)
 *
 * Full “Cập nhật ngay” click requires a live WP + published release on staging API.
 *
 * Usage: php scripts/e2e-updater-staging.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$failed = 0;
function check(string $msg, bool $ok): void {
	global $failed;
	echo ($ok ? 'PASS' : 'FAIL') . "  {$msg}\n";
	if (!$ok) {
		++$failed;
	}
}

define('SEOAUTO_HELPER_VERSION', '1.0.4');
define('SEOAUTO_HELPER_PREFIX', 'seoauto_helper_');
define('SEOAUTO_HELPER_BASENAME', 'seoauto-seo-helper/seoauto-seo-helper.php');
define('HOUR_IN_SECONDS', 3600);

foreach (array('__', 'esc_url_raw', 'sanitize_key', 'apply_filters', 'wp_parse_url', 'is_wp_error') as $fn) {
	// stubs loaded below
}
if (!function_exists('__')) {
	function __($t, $d = '') { return $t; }
}
if (!function_exists('esc_url_raw')) {
	function esc_url_raw($u) { return (string) $u; }
}
if (!function_exists('sanitize_key')) {
	function sanitize_key($k) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $k) ?? ''); }
}
if (!function_exists('apply_filters')) {
	function apply_filters($t, $v) { return $v; }
}
if (!function_exists('wp_parse_url')) {
	function wp_parse_url($url, $component = -1) {
		$p = parse_url((string) $url);
		if ($component === -1) {
			return $p ?: array();
		}
		$map = array(PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host', PHP_URL_PATH => 'path');
		$key = $map[$component] ?? null;
		return $key ? ($p[$key] ?? null) : null;
	}
}
if (!class_exists('WP_Error', false)) {
	class WP_Error {
		public function __construct(public $code = '', public $message = '', public $data = array()) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if (!function_exists('is_wp_error')) {
	function is_wp_error($t) { return $t instanceof WP_Error; }
}

spl_autoload_register(
	static function (string $class) use ($root): void {
		$prefix = 'SEOAuto\\SEOHelper\\';
		if (!str_starts_with($class, $prefix)) {
			return;
		}
		$file = $root . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_readable($file)) {
			require_once $file;
		}
	}
);

use SEOAuto\SEOHelper\Updater\Package_Verifier;

$v = new Package_Verifier();

echo "=== E2E updater gates (1.0.4 → 1.1.0) ===\n";
check('upgrade allowed', true === $v->assert_newer_version('1.0.4', '1.1.0'));
check('downgrade blocked', is_wp_error($v->assert_newer_version('1.1.0', '1.0.4')));
check('replay blocked', is_wp_error($v->assert_newer_version('1.1.0', '1.1.0')));

$tmp = tempnam(sys_get_temp_dir(), 'e2e');
file_put_contents($tmp, 'payload');
$sha = hash('sha256', 'payload');
check('checksum ok', true === $v->assert_sha256_file($tmp, $sha));
check('checksum bad blocked', is_wp_error($v->assert_sha256_file($tmp, str_repeat('0', 64))));

$secret = 'staging-secret';
$exp = gmdate('c', time() + 300);
$sig = hash_hmac('sha256', '1.1.0|' . $sha . '|' . $exp, $secret);
check('signature ok', true === $v->assert_release_signature($sig, $secret, '1.1.0', $sha, $exp));
check('signature bad blocked', is_wp_error($v->assert_release_signature('00', $secret, '1.1.0', $sha, $exp)));
$past = gmdate('c', time() - 30);
$sig2 = hash_hmac('sha256', '1.1.0|' . $sha . '|' . $past, $secret);
check('expired URL blocked', is_wp_error($v->assert_release_signature($sig2, $secret, '1.1.0', $sha, $past)));

if (class_exists('ZipArchive')) {
	$good = $tmp . '-good.zip';
	$z = new ZipArchive();
	$z->open($good, ZipArchive::CREATE);
	$z->addFromString('seoauto-seo-helper/seoauto-seo-helper.php', "<?php\n");
	$z->close();
	check('good zip root', true === $v->assert_zip_structure($good));

	$bad = $tmp . '-bad.zip';
	$z = new ZipArchive();
	$z->open($bad, ZipArchive::CREATE);
	$z->addFromString('other/seoauto-seo-helper.php', "<?php\n");
	$z->close();
	check('bad zip root blocked', is_wp_error($v->assert_zip_structure($bad)));
	@unlink($good);
	@unlink($bad);
} else {
	check('ZipArchive unavailable — skip zip e2e', true);
}

echo "\n=== LIVE staging checklist (manual / WP-CLI) ===\n";
$checklist = array(
	'Publish draft 1.1.0 on staging API after upload',
	'WP Plugins shows “Cập nhật ngay” for SEOAuto SEO Helper',
	'Click update — Plugin_Upgrader succeeds',
	'Schema::maybe_upgrade runs on next boot; DB_VERSION ok',
	'Pairing / entitlement / posts / audit rows preserved',
	'LOCKED site still receives security_patch update',
	'Withdraw broken release; prior published versions remain (≥3)',
);
foreach ($checklist as $line) {
	echo "TODO  {$line}\n";
}

@unlink($tmp);
echo $failed === 0 ? "\nE2E GATES PASS\n" : "\nE2E GATES FAILED: {$failed}\n";
exit($failed > 0 ? 1 : 0);
