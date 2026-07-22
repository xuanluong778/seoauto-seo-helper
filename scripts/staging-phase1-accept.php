<?php
/**
 * Staging acceptance helpers for Phase 1 (run inside WP: wp eval-file).
 *
 * Usage (WP-CLI on staging):
 *   wp eval-file wp-content/plugins/seoauto-seo-helper/scripts/staging-phase1-accept.php
 *
 * Or load via browser only for admins (not recommended).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via WP-CLI: wp eval-file .../staging-phase1-accept.php\n" );
	exit( 1 );
}

if ( ! class_exists( '\\SEOAuto\\SEOHelper\\Plugin' ) ) {
	fwrite( STDERR, "Plugin not loaded.\n" );
	exit( 1 );
}

use SEOAuto\SEOHelper\Post\Schema;
use SEOAuto\SEOHelper\SeoAudit\Audit_Job_Runner;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

$plugin = \SEOAuto\SEOHelper\Plugin::instance();
$runner = $plugin->audit_jobs();

echo "=== Phase 1 staging acceptance ===\n";
echo 'Version: ' . SEOAUTO_HELPER_VERSION . "\n";
echo 'DB_VERSION option: ' . (int) get_option( SEOAUTO_HELPER_PREFIX . 'db_version', 0 ) . ' (code=' . Schema::DB_VERSION . ")\n";

global $wpdb;
foreach ( array( Schema::audit_runs_table(), Schema::audit_issues_table(), Schema::jobs_table() ) as $table ) {
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore
	echo ( $exists === $table ? 'OK   ' : 'MISS ' ) . $table . "\n";
}

// Preserve publish tables (migration safety check).
foreach ( array( Schema::idempotency_table(), Schema::article_map_table(), Schema::media_map_table() ) as $table ) {
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore
	echo ( $exists === $table ? 'OK   ' : 'WARN ' ) . $table . " (pre-existing)\n";
}

$types = Object_Context::audit_post_types();
$count = $runner->engine()->count_objects( $types );
echo 'Audit post types: ' . implode( ', ', $types ) . "\n";
echo "Publishable objects: {$count}\n";
echo 'SEO adapter: ' . $plugin->connection()->detect_seo_plugin() . "\n";
echo 'Locked: ' . ( $plugin->entitlement()->is_locked() ? 'yes' : 'no' ) . "\n";
echo 'Has seo_audit: ' . ( $plugin->entitlement()->has_feature( 'seo_audit' ) ? 'yes' : 'no' ) . "\n";
echo 'WP_DEBUG: ' . ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'yes' : 'no' ) . "\n";

if ( $count < 1 ) {
	echo "No content to scan.\n";
	exit( 0 );
}

$t0     = microtime( true );
$result = $runner->enqueue_scan(
	array(
		'request_id' => 'staging-phase1-' . gmdate( 'YmdHis' ),
		'post_types' => $types,
		'batch_size' => 20,
		'mode'       => 'scan_only',
	)
);
if ( is_wp_error( $result ) ) {
	echo 'ENQUEUE FAIL: ' . $result->get_error_message() . "\n";
	exit( 1 );
}

$job_id = (int) $result['job_id'];
$run_id = (int) $result['run_id'];
echo "Enqueued job={$job_id} run={$run_id}\n";

// Process up to 50 batches synchronously for staging measurement (cron may be slow).
$batches = 0;
while ( $batches < 50 ) {
	$runner->process_queue();
	++$batches;
	$run = $runner->runs()->get( $run_id );
	if ( null === $run ) {
		break;
	}
	$status = (string) ( $run['status'] ?? '' );
	echo "batch {$batches}: status={$status} processed={$run['processed_objects']}/{$run['total_objects']} issues={$run['issues_found']} cursor={$run['cursor_id']}\n";
	if ( in_array( $status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
		break;
	}
}

$elapsed = microtime( true ) - $t0;
$run     = $runner->runs()->get( $run_id );
$issues  = $runner->issues()->query( array( 'run_id' => $run_id, 'limit' => 200 ) );

$by_code = array();
foreach ( $issues as $issue ) {
	$code = (string) ( $issue['issue_code'] ?? '' );
	$by_code[ $code ] = ( $by_code[ $code ] ?? 0 ) + 1;
}
arsort( $by_code );

echo sprintf( "ELAPSED_SECONDS=%.3f\n", $elapsed );
echo 'FINAL status=' . (string) ( $run['status'] ?? '' ) . ' issues_found=' . (int) ( $run['issues_found'] ?? 0 ) . "\n";
echo "Issues by checker code (sample up to 200 rows):\n";
foreach ( $by_code as $code => $n ) {
	echo "  {$code}: {$n}\n";
}

// Idempotency
$replay = $runner->enqueue_scan(
	array(
		'request_id' => (string) $result['request_id'],
		'post_types' => $types,
	)
);
echo 'Idempotent replay: ' . ( ! is_wp_error( $replay ) && ! empty( $replay['idempotent_replay'] ) ? 'yes' : 'no' ) . "\n";

echo "DONE\n";
