<?php
/**
 * Persist audit issues (upsert — no duplicates on retry).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit;

use SEOAuto\SEOHelper\Post\Schema;

final class Issue_Store {

	public function table(): string {
		return Schema::audit_issues_table();
	}

	/**
	 * Replace all issues for one object within a run (idempotent rescan).
	 *
	 * @param list<Audit_Issue> $issues
	 */
	public function replace_for_object( int $run_id, string $object_type, int $object_id, array $issues ): int {
		global $wpdb;
		$table = $this->table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete(
			$table,
			array(
				'run_id'      => $run_id,
				'object_type' => $object_type,
				'object_id'   => $object_id,
			),
			array( '%d', '%s', '%d' )
		);

		$saved = 0;
		foreach ( $issues as $issue ) {
			if ( ! $issue instanceof Audit_Issue ) {
				continue;
			}
			$ok = $wpdb->replace(
				$table,
				array(
					'run_id'          => $run_id,
					'object_type'     => $object_type,
					'object_id'       => $object_id,
					'issue_code'      => $issue->issue_code,
					'severity'        => $issue->severity,
					'risk_level'      => $issue->risk_level,
					'status'          => $issue->status !== '' ? $issue->status : Audit_Codes::STATUS_OPEN,
					'current_value'   => $issue->current_value,
					'suggested_value' => $issue->suggested_value,
					'message'         => $issue->message,
					'context_json'    => wp_json_encode( $issue->context ),
					'created_gmt'     => $now,
					'updated_gmt'     => $now,
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( false !== $ok ) {
				++$saved;
			}
		}
		return $saved;
	}

	/**
	 * @param array{run_id?:int,severity?:string,status?:string,object_type?:string,limit?:int,offset?:int} $args
	 * @return list<array<string,mixed>>
	 */
	public function query( array $args = array() ): array {
		global $wpdb;
		$table = $this->table();
		$where = array( '1=1' );
		$params = array();

		if ( ! empty( $args['run_id'] ) ) {
			$where[]  = 'run_id = %d';
			$params[] = (int) $args['run_id'];
		}
		if ( ! empty( $args['severity'] ) ) {
			$where[]  = 'severity = %s';
			$params[] = sanitize_key( (string) $args['severity'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( (string) $args['status'] );
		}
		if ( ! empty( $args['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$params[] = sanitize_key( (string) $args['object_type'] );
		}

		$limit  = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$sql    = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
			. ' ORDER BY FIELD(severity,"critical","high","medium","low"), id ASC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_map(
			static function ( array $row ): array {
				return Audit_Issue::from_row( $row )->to_array();
			},
			$rows
		);
	}

	public function count_for_run( int $run_id ): int {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE run_id = %d", $run_id ) );
	}
}
