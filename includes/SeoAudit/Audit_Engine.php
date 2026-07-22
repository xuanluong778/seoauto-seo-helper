<?php
/**
 * SEO Audit Engine — scan objects via checkers (read-only Phase 1).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit;

use SEOAuto\SEOHelper\Seo\Seo_Facade;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Broken_Link_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Canonical_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Featured_Image_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Heading_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Image_Alt_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Internal_Link_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Meta_Description_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Mixed_Content_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Robots_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Schema_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Sitemap_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Thin_Content_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Title_Checker;

final class Audit_Engine {

	public const BATCH_SIZE = 20;

	/** @var list<Checker_Interface> */
	private array $checkers;

	private Seo_Meta_Reader $reader;
	private Sitemap_Checker $sitemap;

	public function __construct(
		private Seo_Facade $seo,
		private Issue_Store $issues,
		private Audit_Run_Store $runs,
		?Seo_Meta_Reader $reader = null
	) {
		$this->reader  = $reader ?? new Seo_Meta_Reader( $this->seo );
		$this->sitemap = new Sitemap_Checker();
		$this->checkers = array(
			new Title_Checker(),
			new Meta_Description_Checker(),
			new Heading_Checker(),
			new Image_Alt_Checker(),
			new Featured_Image_Checker(),
			new Internal_Link_Checker(),
			new Broken_Link_Checker(),
			new Canonical_Checker(),
			new Robots_Checker(),
			new Schema_Checker(),
			new Mixed_Content_Checker(),
			new Thin_Content_Checker(),
		);
	}

	/**
	 * @return list<string>
	 */
	public function checker_ids(): array {
		$ids = array_map( static fn( Checker_Interface $c ): string => $c->id(), $this->checkers );
		$ids[] = $this->sitemap->id();
		return $ids;
	}

	/**
	 * Count publishable objects for post types.
	 *
	 * @param list<string> $post_types
	 */
	public function count_objects( array $post_types ): int {
		$q = new \WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => array( 'publish', 'future', 'private' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return (int) $q->found_posts;
	}

	/**
	 * Fetch next batch after cursor (exclusive).
	 *
	 * @param list<string> $post_types
	 * @return list<\WP_Post>
	 */
	public function fetch_batch( array $post_types, int $cursor_id, int $batch_size = self::BATCH_SIZE ): array {
		// WP_Query lacks native cursor; use posts_where filter.
		$filter = static function ( string $where ) use ( $cursor_id ): string {
			global $wpdb;
			if ( $cursor_id > 0 ) {
				$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $cursor_id );
			}
			return $where;
		};
		add_filter( 'posts_where', $filter, 10, 1 );
		$q = new \WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => array( 'publish', 'future', 'private' ),
				'posts_per_page'         => max( 1, min( 50, $batch_size ) ),
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
		remove_filter( 'posts_where', $filter, 10 );

		$posts = $q->posts;
		return is_array( $posts ) ? array_values( array_filter( $posts, static fn( $p ) => $p instanceof \WP_Post ) ) : array();
	}

	/**
	 * Scan one post-like object and upsert issues.
	 *
	 * @param \WP_Post|object $post
	 * @return array{issues:int,codes:list<string>}
	 */
	public function scan_post( int $run_id, object $post ): array {
		$ctx     = Object_Context::from_post( $post, $this->reader );
		$found   = array();
		foreach ( $this->checkers as $checker ) {
			foreach ( $checker->check( $ctx ) as $issue ) {
				$found[] = $issue;
			}
		}
		$saved = $this->issues->replace_for_object( $run_id, $ctx->object_type, $ctx->object_id, $found );
		return array(
			'issues' => $saved,
			'codes'  => array_map( static fn( Audit_Issue $i ): string => $i->issue_code, $found ),
		);
	}

	/**
	 * Site-level checks (sitemap) — once per run.
	 */
	public function scan_site_level( int $run_id ): int {
		$issues = $this->sitemap->check_site();
		return $this->issues->replace_for_object( $run_id, 'site', 0, $issues );
	}

	/**
	 * Process one batch for a run. Returns progress snapshot.
	 *
	 * @param array<string,mixed> $run
	 * @return array{done:bool,processed:int,cursor:int,issues_delta:int,cancelled:bool}
	 */
	public function process_batch( array $run, int $batch_size = self::BATCH_SIZE ): array {
		$run_id = (int) ( $run['id'] ?? 0 );
		if ( $run_id <= 0 ) {
			return array(
				'done'         => true,
				'processed'    => 0,
				'cursor'       => 0,
				'issues_delta' => 0,
				'cancelled'    => false,
			);
		}

		$fresh = $this->runs->get( $run_id );
		if ( null === $fresh || Audit_Run_Store::STATUS_CANCELLED === ( $fresh['status'] ?? '' ) ) {
			return array(
				'done'         => true,
				'processed'    => 0,
				'cursor'       => (int) ( $run['cursor_id'] ?? 0 ),
				'issues_delta' => 0,
				'cancelled'    => true,
			);
		}

		/** @var list<string> $types */
		$types  = is_array( $fresh['post_types'] ?? null ) ? $fresh['post_types'] : Object_Context::audit_post_types();
		$cursor = (int) ( $fresh['cursor_id'] ?? 0 );
		$meta   = is_array( $fresh['meta'] ?? null ) ? $fresh['meta'] : array();

		$issues_delta = 0;
		if ( empty( $meta['site_checked'] ) ) {
			$issues_delta += $this->scan_site_level( $run_id );
			$meta['site_checked'] = true;
		}

		$posts = $this->fetch_batch( $types, $cursor, $batch_size );
		if ( $posts === array() ) {
			$total_issues = $this->issues->count_for_run( $run_id );
			$this->runs->update(
				$run_id,
				array(
					'status'            => Audit_Run_Store::STATUS_COMPLETED,
					'issues_found'      => $total_issues,
					'finished_gmt'      => gmdate( 'Y-m-d H:i:s' ),
					'meta_json'         => wp_json_encode( $meta ),
					'seo_adapter'       => $this->seo->active_id(),
				)
			);
			return array(
				'done'         => true,
				'processed'    => 0,
				'cursor'       => $cursor,
				'issues_delta' => $issues_delta,
				'cancelled'    => false,
			);
		}

		$processed = 0;
		$last_id   = $cursor;
		foreach ( $posts as $post ) {
			$result        = $this->scan_post( $run_id, $post );
			$issues_delta += (int) $result['issues'];
			$last_id       = (int) $post->ID;
			++$processed;
		}

		$new_processed = (int) ( $fresh['processed_objects'] ?? 0 ) + $processed;
		$total_issues  = $this->issues->count_for_run( $run_id );
		$this->runs->update(
			$run_id,
			array(
				'status'             => Audit_Run_Store::STATUS_RUNNING,
				'cursor_id'          => $last_id,
				'processed_objects'  => $new_processed,
				'issues_found'       => $total_issues,
				'started_gmt'        => $fresh['started_gmt'] ?: gmdate( 'Y-m-d H:i:s' ),
				'meta_json'          => wp_json_encode( $meta ),
				'seo_adapter'        => $this->seo->active_id(),
			)
		);

		$done = count( $posts ) < $batch_size;
		if ( $done ) {
			$this->runs->update(
				$run_id,
				array(
					'status'       => Audit_Run_Store::STATUS_COMPLETED,
					'finished_gmt' => gmdate( 'Y-m-d H:i:s' ),
					'issues_found' => $this->issues->count_for_run( $run_id ),
				)
			);
		}

		return array(
			'done'         => $done,
			'processed'    => $processed,
			'cursor'       => $last_id,
			'issues_delta' => $issues_delta,
			'cancelled'    => false,
		);
	}
}
