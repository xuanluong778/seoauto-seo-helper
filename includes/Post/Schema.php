<?php
/**
 * DB schema for idempotency + article → post mapping.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Post;

final class Schema {

	public const DB_VERSION = 2;
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

		$media = self::media_map_table();
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
	}
}
