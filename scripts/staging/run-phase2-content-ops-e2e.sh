#!/usr/bin/env bash
# Staging E2E: Preview → Backup → Apply → Recheck → Rollback (site1 only).
# Runs via wp-load — does not touch production.
set -euo pipefail
SITE="${1:-/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn}"
cd "$SITE"

php -d display_errors=1 <<'PHP'
<?php
require './wp-load.php';

if ( ! defined( 'SEOAUTO_HELPER_VERSION' ) ) {
	fwrite( STDERR, "PLUGIN_NOT_LOADED\n" );
	exit( 1 );
}

echo 'version=', SEOAUTO_HELPER_VERSION, PHP_EOL;
\SEOAuto\SEOHelper\Post\Schema::maybe_upgrade();
echo 'db_version=', (int) get_option( 'seoauto_helper_db_version' ), PHP_EOL;

$plugin = \SEOAuto\SEOHelper\Plugin::instance();
$ops    = $plugin->content_ops();
$conn   = $plugin->connection();

if ( ! $conn->has_credentials() ) {
	fwrite( STDERR, "NOT_PAIRED\n" );
	exit( 1 );
}

// Ensure we can mutate for staging test (do not change entitlement store permanently beyond check).
if ( $plugin->entitlement()->is_locked() ) {
	fwrite( STDERR, "LOCKED\n" );
	exit( 1 );
}

$post_id = wp_insert_post(
	array(
		'post_title'   => 'ContentOps E2E ' . gmdate( 'His' ),
		'post_content' => 'ORIGINAL_BODY_' . wp_generate_password( 6, false ),
		'post_status'  => 'publish',
		'post_type'    => 'post',
		'post_excerpt' => 'orig-excerpt',
	),
	true
);
if ( is_wp_error( $post_id ) ) {
	fwrite( STDERR, 'CREATE_FAIL ' . $post_id->get_error_message() . PHP_EOL );
	exit( 1 );
}
$post_id = (int) $post_id;
$before  = get_post( $post_id );
echo 'post_id=', $post_id, PHP_EOL;

$proposed = array(
	'title'   => 'ContentOps E2E UPDATED',
	'content' => 'UPDATED_BODY_SAFE',
	'excerpt' => 'new-excerpt',
);

// 1) Preview must not mutate
$prev = $ops->preview(
	array(
		'items' => array(
			array(
				'post_id'  => $post_id,
				'proposed' => $proposed,
				'reason'   => 'e2e preview',
			),
		),
	)
);
if ( is_wp_error( $prev ) ) {
	fwrite( STDERR, 'PREVIEW_FAIL ' . $prev->get_error_message() . PHP_EOL );
	exit( 1 );
}
$after_preview = get_post( $post_id );
if ( $after_preview->post_title !== $before->post_title || $after_preview->post_content !== $before->post_content ) {
	fwrite( STDERR, "PREVIEW_MUTATED\n" );
	exit( 1 );
}
echo "preview_ok mutates=0\n";

$request_id = 'e2e-co-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false );

// 2) Backup
$bak = $ops->backup(
	array(
		'request_id' => $request_id,
		'items'      => array(
			array(
				'post_id'         => $post_id,
				'proposed'        => $proposed,
				'reason'          => 'e2e backup',
				'idempotency_key' => 'p' . $post_id,
			),
		),
	)
);
if ( is_wp_error( $bak ) ) {
	fwrite( STDERR, 'BACKUP_FAIL ' . $bak->get_error_message() . PHP_EOL );
	exit( 1 );
}
if ( ! empty( $bak['apply_blocked'] ) || ( $bak['status'] ?? '' ) === 'backup_failed' ) {
	fwrite( STDERR, "BACKUP_BLOCKED_UNEXPECTED\n" );
	print_r( $bak );
	exit( 1 );
}
$batch_id = (int) $bak['batch_id'];
echo 'backup_ok batch=', $batch_id, ' status=', $bak['status'], PHP_EOL;

// Idempotent backup replay
$bak2 = $ops->backup( array( 'request_id' => $request_id, 'items' => array() ) );
if ( is_wp_error( $bak2 ) || (int) $bak2['batch_id'] !== $batch_id ) {
	fwrite( STDERR, "BACKUP_IDEMPOTENCY_FAIL\n" );
	exit( 1 );
}
echo "backup_idempotent_ok\n";

// 3) Apply
$app = $ops->apply( array( 'batch_id' => $batch_id ) );
if ( is_wp_error( $app ) ) {
	fwrite( STDERR, 'APPLY_FAIL ' . $app->get_error_message() . PHP_EOL );
	exit( 1 );
}
$applied = get_post( $post_id );
if ( $applied->post_title !== 'ContentOps E2E UPDATED' || $applied->post_content !== 'UPDATED_BODY_SAFE' ) {
	fwrite( STDERR, "APPLY_CONTENT_MISMATCH\n" );
	exit( 1 );
}
echo 'apply_ok status=', $app['status'], PHP_EOL;

// Retry apply must not duplicate / must stay applied
$app2 = $ops->apply( array( 'batch_id' => $batch_id ) );
if ( is_wp_error( $app2 ) ) {
	fwrite( STDERR, 'APPLY_RETRY_FAIL ' . $app2->get_error_message() . PHP_EOL );
	exit( 1 );
}
echo "apply_retry_idempotent_ok\n";

// 4) Recheck
$rec = $ops->recheck( array( 'batch_id' => $batch_id ) );
if ( is_wp_error( $rec ) ) {
	fwrite( STDERR, 'RECHECK_FAIL ' . $rec->get_error_message() . PHP_EOL );
	exit( 1 );
}
if ( ( $rec['status'] ?? '' ) === 'recheck_failed' ) {
	fwrite( STDERR, "RECHECK_STATUS_FAILED\n" );
	print_r( $rec );
	exit( 1 );
}
echo 'recheck_ok status=', $rec['status'], PHP_EOL;

// 5) Rollback preview then real
$rb_prev = $ops->rollback( array( 'batch_id' => $batch_id, 'preview_only' => true ) );
if ( is_wp_error( $rb_prev ) || empty( $rb_prev['preview_only'] ) ) {
	fwrite( STDERR, "ROLLBACK_PREVIEW_FAIL\n" );
	exit( 1 );
}
echo "rollback_preview_ok\n";

$rb = $ops->rollback( array( 'batch_id' => $batch_id ) );
if ( is_wp_error( $rb ) ) {
	fwrite( STDERR, 'ROLLBACK_FAIL ' . $rb->get_error_message() . PHP_EOL );
	exit( 1 );
}
$restored = get_post( $post_id );
if ( $restored->post_title !== $before->post_title || $restored->post_content !== $before->post_content ) {
	fwrite( STDERR, "ROLLBACK_CONTENT_MISMATCH title={$restored->post_title}\n" );
	exit( 1 );
}
echo 'rollback_ok status=', $rb['status'], PHP_EOL;

// 6) Conflict path: apply again, mutate post, rollback should conflict
$request_id2 = $request_id . '-c';
$bak3 = $ops->backup(
	array(
		'request_id' => $request_id2,
		'items'      => array(
			array(
				'post_id'         => $post_id,
				'proposed'        => array( 'title' => 'Conflict Target' ),
				'reason'          => 'conflict setup',
				'idempotency_key' => 'p' . $post_id,
			),
		),
	)
);
$ops->apply( array( 'batch_id' => (int) $bak3['batch_id'] ) );
wp_update_post( array( 'ID' => $post_id, 'post_title' => 'USER_EDITED_AFTER_APPLY' ) );
$rb_conflict = $ops->rollback( array( 'batch_id' => (int) $bak3['batch_id'] ) );
$st = is_wp_error( $rb_conflict ) ? $rb_conflict->get_error_code() : (string) ( $rb_conflict['status'] ?? '' );
$items = is_array( $rb_conflict ) ? ( $rb_conflict['items'] ?? array() ) : array();
$has_conflict = $st === 'conflict';
foreach ( $items as $it ) {
	if ( ( $it['status'] ?? '' ) === 'conflict' ) {
		$has_conflict = true;
	}
}
if ( ! $has_conflict ) {
	fwrite( STDERR, "CONFLICT_EXPECTED_FAIL status={$st}\n" );
	print_r( $rb_conflict );
	exit( 1 );
}
echo "rollback_conflict_ok\n";

// Pairing still present
if ( ! $conn->has_credentials() ) {
	fwrite( STDERR, "PAIRING_LOST\n" );
	exit( 1 );
}
echo "pairing_preserved_ok\n";

// Cleanup test posts
wp_delete_post( $post_id, true );

echo "CONTENT_OPS_E2E_PASS\n";
PHP
