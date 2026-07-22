<?php
/**
 * DB schema for publish maps + SEO audit runs/issues/jobs.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Post;

final class Schema {

	public const DB_VERSION        = 3;
	public const OPTION_DB_VERSION = 'db_version';

	public static function idempotency_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'seoauto_helper_idempotency';
	}

	public static function article_map_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'seoauto_helper_article_map';
	}

	public static function media_map_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'seoauto_helper_media_map';
	}

	public static function audit_runs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'seoauto_helper_audit_runs';
	}

	public static function audit_issues_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'seoauto_helper_audit_issues';
	}

	public static function jobs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'seoauto_helper_jobs';
	}

	public static function maybe_upgrade(): void {
		$current = (int) get_option( SEOAUTO_HELPER_PREFIX . self::OPTION_DB_VERSION, 0 );
		if ( $current >= self::DB_VERSION ) {
			return;
		}
		self::install();
		update_option( SEOAUTO_HELPER_PREFIX . self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	public static function install(): void {
		global $wpdb;

		require_once \ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$idem    = self::idempotency_table();
		$map     = self::article_map_table();

		$sql_idem = "CREATE TABLE {$idem} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			request_id VARCHAR(128) NOT NULL,
			connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source_article_id VARCHAR(191) NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			response_json LONGTEXT NULL,
			error_code VARCHAR(64) NULL,
			created_gmt DATETIME NOT NULL,
			updated_gmt DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_request_id (request_id),
			KEY idx_conn_article (connection_id, source_article_id),
			KEY idx_status (status)
		) {$charset};";

		$sql_map = "CREATE TABLE {$map} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source_article_id VARCHAR(191) NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL,
			created_gmt DATETIME NOT NULL,
			updated_gmt DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_conn_article (connection_id, source_article_id),
			KEY idx_post_id (post_id)
		) {$charset};";

		dbDelta( $sql_idem );
		dbDelta( $sql_map );

		$media     = self::media_map_table();
		$sql_media = "CREATE TABLE {$media} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source_image_id VARCHAR(191) NULL,
			file_hash CHAR(64) NOT NULL,
			attachment_id BIGINT UNSIGNED NOT NULL,
			created_gmt DATETIME NOT NULL,
			updated_gmt DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_conn_hash (connection_id, file_hash),
			UNIQUE KEY uq_conn_source (connection_id, source_image_id),
			KEY idx_attachment (attachment_id)
		) {$charset};";
		dbDelta( $sql_media );

		$runs     = self::audit_runs_table();
		$sql_runs = "CREATE TABLE {$runs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			request_id VARCHAR(128) NOT NULL,
			job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			mode VARCHAR(32) NOT NULL DEFAULT 'scan_only',
			post_types TEXT NULL,
			total_objects INT UNSIGNED NOT NULL DEFAULT 0,
			processed_objects INT UNSIGNED NOT NULL DEFAULT 0,
			issues_found INT UNSIGNED NOT NULL DEFAULT 0,
			cursor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			seo_adapter VARCHAR(32) NOT NULL DEFAULT '',
			error_code VARCHAR(64) NULL,
			error_message TEXT NULL,
			meta_json LONGTEXT NULL,
			started_gmt DATETIME NULL,
			finished_gmt DATETIME NULL,
			created_gmt DATETIME NOT NULL,
			updated_gmt DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_request_id (request_id),
			KEY idx_status (status),
			KEY idx_job_id (job_id)
		) {$charset};";
		dbDelta( $sql_runs );

		$issues     = self::audit_issues_table();
		$sql_issues = "CREATE TABLE {$issues} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id BIGINT UNSIGNED NOT NULL,
			object_type VARCHAR(32) NOT NULL DEFAULT 'post',
			object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			issue_code VARCHAR(64) NOT NULL,
			severity VARCHAR(16) NOT NULL DEFAULT 'medium',
			risk_level VARCHAR(16) NOT NULL DEFAULT 'safe',
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			current_value LONGTEXT NULL,
			suggested_value LONGTEXT NULL,
			message TEXT NULL,
			context_json LONGTEXT NULL,
			created_gmt DATETIME NOT NULL,
			updated_gmt DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_run_object_code (run_id, object_type, object_id, issue_code),
			KEY idx_run_severity (run_id, severity),
			KEY idx_run_status (run_id, status),
			KEY idx_object (object_type, object_id)
		) {$charset};";
		dbDelta( $sql_issues );

		$jobs     = self::jobs_table();
		$sql_jobs = "CREATE TABLE {$jobs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			request_id VARCHAR(128) NOT NULL,
			job_type VARCHAR(32) NOT NULL DEFAULT 'audit_scan',
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			batch_size SMALLINT UNSIGNED NOT NULL DEFAULT 20,
			cursor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
			payload_json LONGTEXT NULL,
			result_json LONGTEXT NULL,
			error_code VARCHAR(64) NULL,
			error_message TEXT NULL,
			locked_until_gmt DATETIME NULL,
			started_gmt DATETIME NULL,
			finished_gmt DATETIME NULL,
			created_gmt DATETIME NOT NULL,
			updated_gmt DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_request_id (request_id),
			KEY idx_status_type (status, job_type),
			KEY idx_run_id (run_id)
		) {$charset};";
		dbDelta( $sql_jobs );
	}
}
