<?php
/**
 * Read SEO meta via the active Rank Math / Yoast / AIOSEO / Native adapter.
 *
 * Phase 1 read-only — does not write meta.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit;

use SEOAuto\SEOHelper\Seo\Native_Adapter;
use SEOAuto\SEOHelper\Seo\Seo_Facade;

final class Seo_Meta_Reader {

	public function __construct( private Seo_Facade $seo ) {}

	/**
	 * @return array{
	 *   adapter:string,
	 *   title:string,
	 *   description:string,
	 *   focus_keyword:string,
	 *   canonical:string,
	 *   robots_index:bool|null,
	 *   robots_follow:bool|null,
	 *   schema_type:string
	 * }
	 */
	public function read( int $post_id ): array {
		$adapter = $this->seo->active_id();
		$data    = match ( $adapter ) {
			'rankmath' => $this->read_rankmath( $post_id ),
			'yoast'    => $this->read_yoast( $post_id ),
			'aioseo'   => $this->read_aioseo( $post_id ),
			default    => $this->read_native( $post_id ),
		};

		if ( $data['title'] === '' ) {
			$data['title'] = (string) get_the_title( $post_id );
		}
		// Do not invent canonical — empty means "not set in SEO meta" for audit.

		$data['adapter'] = $adapter;
		return $data;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function read_native( int $post_id ): array {
		$index = get_post_meta( $post_id, Native_Adapter::META_ROBOTS_I, true );
		$follow = get_post_meta( $post_id, Native_Adapter::META_ROBOTS_F, true );
		return array(
			'title'          => (string) get_post_meta( $post_id, Native_Adapter::META_TITLE, true ),
			'description'    => (string) get_post_meta( $post_id, Native_Adapter::META_DESC, true ),
			'focus_keyword'  => (string) get_post_meta( $post_id, Native_Adapter::META_FOCUS, true ),
			'canonical'      => (string) get_post_meta( $post_id, Native_Adapter::META_CANONICAL, true ),
			'robots_index'   => $index === '' ? null : ( $index === '1' || $index === 1 || $index === true ),
			'robots_follow'  => $follow === '' ? null : ( $follow === '1' || $follow === 1 || $follow === true ),
			'schema_type'    => (string) get_post_meta( $post_id, Native_Adapter::META_SCHEMA, true ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function read_yoast( int $post_id ): array {
		$noindex  = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		$nofollow = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );
		$schema   = (string) get_post_meta( $post_id, '_yoast_wpseo_schema_article_type', true );
		if ( $schema === '' ) {
			$schema = (string) get_post_meta( $post_id, '_yoast_wpseo_schema_page_type', true );
		}
		return array(
			'title'         => (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ),
			'description'   => (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
			'focus_keyword' => (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ),
			'canonical'     => (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true ),
			'robots_index'  => $noindex === '' ? null : ( $noindex !== '1' ),
			'robots_follow' => $nofollow === '' ? null : ( $nofollow !== '1' ),
			'schema_type'   => $schema,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function read_rankmath( int $post_id ): array {
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		$index  = null;
		$follow = null;
		if ( is_array( $robots ) ) {
			$index  = ! in_array( 'noindex', $robots, true );
			$follow = ! in_array( 'nofollow', $robots, true );
		}
		$schema = (string) get_post_meta( $post_id, 'rank_math_snippet_article_type', true );
		if ( $schema === '' ) {
			$schema = (string) get_post_meta( $post_id, 'rank_math_rich_snippet', true );
		}
		return array(
			'title'         => (string) get_post_meta( $post_id, 'rank_math_title', true ),
			'description'   => (string) get_post_meta( $post_id, 'rank_math_description', true ),
			'focus_keyword' => (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
			'canonical'     => (string) get_post_meta( $post_id, 'rank_math_canonical_url', true ),
			'robots_index'  => $index,
			'robots_follow' => $follow,
			'schema_type'   => $schema,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function read_aioseo( int $post_id ): array {
		$noindex  = get_post_meta( $post_id, '_aioseo_noindex', true );
		$nofollow = get_post_meta( $post_id, '_aioseo_nofollow', true );
		return array(
			'title'         => (string) get_post_meta( $post_id, '_aioseo_title', true ),
			'description'   => (string) get_post_meta( $post_id, '_aioseo_description', true ),
			'focus_keyword' => (string) get_post_meta( $post_id, '_aioseo_keywords', true ),
			'canonical'     => (string) get_post_meta( $post_id, '_aioseo_canonical_url', true ),
			'robots_index'  => ( $noindex === '' || $noindex === null ) ? null : empty( $noindex ),
			'robots_follow' => ( $nofollow === '' || $nofollow === null ) ? null : empty( $nofollow ),
			'schema_type'   => (string) get_post_meta( $post_id, '_aioseo_schema_type', true ),
		);
	}
}
