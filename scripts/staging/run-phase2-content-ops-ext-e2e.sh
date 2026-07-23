#!/usr/bin/env bash
# Extended Phase 2 ContentOps E2E (staging WP only).
# Covers: feature gate, backup-fail blocks apply, lock, retry, conflict,
# retention purge, IDOR, SEO restore (Rank Math / Yoast / AIOSEO / native).
set -euo pipefail
SITE="${1:-/var/www/siteauto_vn_usr/data/www/siteauto.vn/sites/seohelper-staging.siteauto.vn}"
cd "$SITE"

php -d display_errors=1 <<'PHP'
<?php
require './wp-load.php';

function fail( string $m ): void {
	fwrite( STDERR, "FAIL $m\n" );
	exit( 1 );
}
function ok( string $m ): void {
	echo "PASS $m\n";
}

if ( ! defined( 'SEOAUTO_HELPER_VERSION' ) ) {
	fail( 'PLUGIN_NOT_LOADED' );
}
echo 'version=', SEOAUTO_HELPER_VERSION, PHP_EOL;
\SEOAuto\SEOHelper\Post\Schema::maybe_upgrade();
echo 'db_version=', (int) get_option( 'seoauto_helper_db_version' ), PHP_EOL;

$plugin = \SEOAuto\SEOHelper\Plugin::instance();
$ops    = $plugin->content_ops();
$conn   = $plugin->connection();
$ent    = $plugin->entitlement();

if ( ! $conn->has_credentials() ) {
	fail( 'NOT_PAIRED' );
}
if ( $ent->is_locked() ) {
	fail( 'LOCKED' );
}

// --- Ensure content_ops in entitlement snapshot (SaaS feature gate) ---
$ent_json = (string) $conn->option( 'entitlement_json', '' );
$ent_arr  = json_decode( $ent_json, true );
if ( ! is_array( $ent_arr ) ) {
	fail( 'NO_ENTITLEMENT_JSON' );
}
$features = $ent_arr['enabled_features'] ?? array();
if ( ! is_array( $features ) ) {
	$features = array();
}
if ( ! in_array( 'content_ops', $features, true ) ) {
	// Staging: inject feature into cached entitlement WITHOUT changing signature verifier path
	// by refreshing from SaaS first; if still missing, merge for local service tests only after refresh attempt.
	$ent->refresh_check( 'content_ops_e2e' );
	$ent_arr  = json_decode( (string) $conn->option( 'entitlement_json', '' ), true );
	$features = is_array( $ent_arr['enabled_features'] ?? null ) ? $ent_arr['enabled_features'] : array();
}
if ( ! in_array( 'content_ops', $features, true ) ) {
	fail( 'FEATURE_CONTENT_OPS_MISSING_FROM_SAAS — deploy SaaS SEO_HELPER_CAPABILITIES with content_ops then refresh entitlement' );
}
ok( 'feature_gate_content_ops_present' );

$site_id_before = (string) $conn->option( 'site_id', '' );
$posts_before   = (int) wp_count_posts( 'post' )->publish;
$media_before   = (int) wp_count_posts( 'attachment' )->inherit;

$post_id = wp_insert_post(
	array(
		'post_title'   => 'CO Ext ' . gmdate( 'His' ),
		'post_content' => 'ORIG_' . wp_generate_password( 6, false ),
		'post_status'  => 'publish',
		'post_excerpt' => 'ex-orig',
		'post_type'    => 'post',
	),
	true
);
if ( is_wp_error( $post_id ) ) {
	fail( 'CREATE ' . $post_id->get_error_message() );
}
$post_id = (int) $post_id;
$before  = get_post( $post_id );

// Detect SEO plugin
$seo_id = $plugin->seo()->active_id();
echo "seo_adapter=$seo_id\n";
$wf = is_plugin_active( 'wordfence/wordfence.php' ) ? 'yes' : 'no';
echo "wordfence=$wf\n";

// Seed SEO meta via facade
$seo_seed = array(
	'title'         => 'SEO Title Orig',
	'description'   => 'SEO Desc Orig',
	'focus_keyword' => 'kw-orig',
	'canonical'     => home_url( '/orig-canonical/' ),
);
$plugin->seo()->sync_post_meta( $post_id, $seo_seed );

$proposed = array(
	'title'   => 'CO Ext UPDATED',
	'content' => 'UPDATED_BODY',
	'excerpt' => 'ex-new',
	'seo'     => array(
		'title'         => 'SEO Title New',
		'description'   => 'SEO Desc New',
		'focus_keyword' => 'kw-new',
		'canonical'     => home_url( '/new-canonical/' ),
	),
);

// 1) Preview no mutate
$prev = $ops->preview( array( 'items' => array( array( 'post_id' => $post_id, 'proposed' => $proposed, 'reason' => 'ext' ) ) ) );
if ( is_wp_error( $prev ) ) {
	fail( 'PREVIEW ' . $prev->get_error_message() );
}
$p = get_post( $post_id );
if ( $p->post_title !== $before->post_title ) {
	fail( 'PREVIEW_MUTATED' );
}
ok( 'preview_readonly' );

// 2) Backup fail blocks Apply — force missing post in batch with valid post
$rid_bad = 'e2e-bad-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 4, false );
$bak_bad = $ops->backup(
	array(
		'request_id' => $rid_bad,
		'items'      => array(
			array(
				'post_id'         => $post_id,
				'proposed'        => $proposed,
				'reason'          => 'ok item',
				'idempotency_key' => 'ok',
			),
			array(
				'post_id'         => 999999991,
				'proposed'        => array( 'title' => 'x' ),
				'reason'          => 'missing',
				'idempotency_key' => 'bad',
			),
		),
	)
);
if ( is_wp_error( $bak_bad ) ) {
	fail( 'BACKUP_BAD ' . $bak_bad->get_error_message() );
}
if ( empty( $bak_bad['apply_blocked'] ) && ( $bak_bad['status'] ?? '' ) !== 'backup_failed' ) {
	fail( 'EXPECTED_APPLY_BLOCKED status=' . ( $bak_bad['status'] ?? '' ) );
}
$apply_blocked = $ops->apply( array( 'batch_id' => (int) $bak_bad['batch_id'] ) );
if ( ! is_wp_error( $apply_blocked ) || $apply_blocked->get_error_code() !== 'seoauto_apply_blocked_backup' ) {
	fail( 'APPLY_SHOULD_BLOCK code=' . ( is_wp_error( $apply_blocked ) ? $apply_blocked->get_error_code() : 'none' ) );
}
ok( 'backup_fail_blocks_apply' );

// 3) Happy path backup/apply/recheck/rollback
$rid = 'e2e-ok-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 4, false );
$bak = $ops->backup(
	array(
		'request_id' => $rid,
		'items'      => array(
			array(
				'post_id'         => $post_id,
				'proposed'        => $proposed,
				'reason'          => 'happy',
				'idempotency_key' => 'p' . $post_id,
			),
		),
	)
);
if ( is_wp_error( $bak ) || ! empty( $bak['apply_blocked'] ) ) {
	fail( 'BACKUP_OK_PATH' );
}
$batch_id = (int) $bak['batch_id'];

// Idempotent backup
$bak2 = $ops->backup( array( 'request_id' => $rid, 'items' => array() ) );
if ( is_wp_error( $bak2 ) || (int) $bak2['batch_id'] !== $batch_id ) {
	fail( 'BACKUP_IDEMPOTENCY' );
}
ok( 'backup_idempotent' );

$app = $ops->apply( array( 'batch_id' => $batch_id ) );
if ( is_wp_error( $app ) ) {
	fail( 'APPLY ' . $app->get_error_message() );
}
$applied = get_post( $post_id );
if ( $applied->post_title !== 'CO Ext UPDATED' ) {
	fail( 'APPLY_TITLE' );
}
ok( 'apply' );

$app2 = $ops->apply( array( 'batch_id' => $batch_id ) );
if ( is_wp_error( $app2 ) ) {
	fail( 'APPLY_RETRY' );
}
ok( 'apply_retry_idempotent' );

$rec = $ops->recheck( array( 'batch_id' => $batch_id ) );
if ( is_wp_error( $rec ) || ( $rec['status'] ?? '' ) === 'recheck_failed' ) {
	fail( 'RECHECK' );
}
ok( 'recheck' );

// SEO after apply
$reader = new \SEOAuto\SEOHelper\SeoAudit\Seo_Meta_Reader( $plugin->seo() );
$seo_now = $reader->read( $post_id );
if ( ( $seo_now['title'] ?? '' ) !== 'SEO Title New' ) {
	fail( 'SEO_APPLY_TITLE got=' . ( $seo_now['title'] ?? '' ) );
}
ok( 'seo_apply_' . $seo_id );

$rb = $ops->rollback( array( 'batch_id' => $batch_id ) );
if ( is_wp_error( $rb ) ) {
	fail( 'ROLLBACK ' . $rb->get_error_message() );
}
$restored = get_post( $post_id );
if ( $restored->post_title !== $before->post_title || $restored->post_content !== $before->post_content ) {
	fail( 'ROLLBACK_CONTENT' );
}
$seo_rb = $reader->read( $post_id );
if ( ( $seo_rb['title'] ?? '' ) !== 'SEO Title Orig' ) {
	fail( 'SEO_ROLLBACK_TITLE got=' . ( $seo_rb['title'] ?? '' ) );
}
ok( 'seo_rollback_' . $seo_id );
ok( 'rollback' );

// 4) Concurrent lock
$lock = new \SEOAuto\SEOHelper\ContentOps\Content_Lock();
$tok_a = 'tokA_' . wp_generate_password( 6, false );
$tok_b = 'tokB_' . wp_generate_password( 6, false );
$a = $lock->acquire( $post_id, (int) $conn->option( 'connection_id', 0 ), 1, 1, $tok_a );
if ( is_wp_error( $a ) ) {
	fail( 'LOCK_A' );
}
$b = $lock->acquire( $post_id, (int) $conn->option( 'connection_id', 0 ), 2, 2, $tok_b );
if ( ! is_wp_error( $b ) || $b->get_error_code() !== 'seoauto_content_locked' ) {
	fail( 'LOCK_CONCURRENT_EXPECTED' );
}
$lock->release( $post_id, $tok_a );
ok( 'concurrent_lock' );

// 5) Conflict rollback
$rid_c = $rid . '-conflict';
$bak_c = $ops->backup(
	array(
		'request_id' => $rid_c,
		'items'      => array(
			array(
				'post_id'         => $post_id,
				'proposed'        => array( 'title' => 'Conflict Target' ),
				'reason'          => 'c',
				'idempotency_key' => 'c' . $post_id,
			),
		),
	)
);
$ops->apply( array( 'batch_id' => (int) $bak_c['batch_id'] ) );
wp_update_post( array( 'ID' => $post_id, 'post_title' => 'USER_EDITED' ) );
$rb_c = $ops->rollback( array( 'batch_id' => (int) $bak_c['batch_id'] ) );
$has_conflict = false;
if ( is_array( $rb_c ) ) {
	if ( ( $rb_c['status'] ?? '' ) === 'conflict' ) {
		$has_conflict = true;
	}
	foreach ( $rb_c['items'] ?? array() as $it ) {
		if ( ( $it['status'] ?? '' ) === 'conflict' ) {
			$has_conflict = true;
		}
	}
}
if ( ! $has_conflict ) {
	fail( 'CONFLICT_EXPECTED' );
}
ok( 'rollback_conflict' );

// 6) IDOR — forged connection_id on batch lookup
global $wpdb;
$table = \SEOAuto\SEOHelper\Post\Schema::content_batches_table();
$foreign = $ops->get_batch( (int) $bak['batch_id'] );
// Own batch should work
if ( is_wp_error( $foreign ) ) {
	fail( 'OWN_BATCH' );
}
// Inject foreign connection_id then deny
$wpdb->update( $table, array( 'connection_id' => 999888777 ), array( 'id' => (int) $bak['batch_id'] ), array( '%d' ), array( '%d' ) );
$idor = $ops->get_batch( (int) $bak['batch_id'] );
if ( ! is_wp_error( $idor ) || $idor->get_error_code() !== 'seoauto_forbidden' ) {
	fail( 'IDOR_EXPECTED' );
}
// restore ownership for cleanup
$wpdb->update( $table, array( 'connection_id' => (int) $conn->option( 'connection_id', 0 ) ), array( 'id' => (int) $bak['batch_id'] ), array( '%d' ), array( '%d' ) );
ok( 'idor_blocked' );

// 7) Retention purge (force expire then purge)
$bt = \SEOAuto\SEOHelper\Post\Schema::content_backups_table();
$wpdb->query( "UPDATE {$bt} SET expires_gmt = '2000-01-01 00:00:00' WHERE post_id = {$post_id}" );
$purged = $ops->purge_expired();
if ( (int) ( $purged['backups_deleted'] ?? 0 ) < 1 ) {
	fail( 'RETENTION_PURGE' );
}
ok( 'retention_purge' );

// Pairing + counts preserved
if ( (string) $conn->option( 'site_id', '' ) !== $site_id_before ) {
	fail( 'SITE_ID_CHANGED' );
}
if ( ! $conn->has_credentials() ) {
	fail( 'PAIRING_LOST' );
}
ok( 'pairing_preserved' );

wp_delete_post( $post_id, true );
echo "CONTENT_OPS_EXT_E2E_PASS\n";
PHP
