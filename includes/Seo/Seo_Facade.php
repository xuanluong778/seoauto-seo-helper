<?php
/**
 * SEO adapter facade — one active adapter only (no duplicate head tags).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Seo;

final class Seo_Facade {

	/** @var list<Seo_Adapter_Interface> */
	private array $adapters;

	private Native_Adapter $native;

	public function __construct() {
		$this->native = new Native_Adapter();
		// Priority: Rank Math → Yoast → AIOSEO → native.
		$this->adapters = array(
			new RankMath_Adapter(),
			new Yoast_Adapter(),
			new AIOSEO_Adapter(),
			$this->native,
		);
	}

	public function register_hooks(): void {
		$active = $this->active_adapter();
		// Only native emits canonical/robots/OG/schema. SEO plugins own their own output.
		if ( $active->id() === 'native' ) {
			$this->native->register_frontend();
		}
	}

	public function active_adapter(): Seo_Adapter_Interface {
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->id() === 'native' ) {
				continue;
			}
			if ( $adapter->is_active() ) {
				return $adapter;
			}
		}
		return $this->native;
	}

	public function active_id(): string {
		return $this->active_adapter()->id();
	}

	/**
	 * Sync SEO fields through the single active adapter.
	 * Always mirrors core title/desc/focus into native keys for admin/debug,
	 * but frontend tags only come from the active adapter's system.
	 *
	 * @param array<string,mixed> $seo
	 */
	public function sync_post_meta( int $post_id, array $seo ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$payload = Seo_Payload::from_array( $seo );
		if ( $payload->is_empty() ) {
			return;
		}

		$active = $this->active_adapter();

		// Keep a lightweight internal mirror for status/debug (not rendered when plugin SEO is active).
		if ( $active->id() !== 'native' ) {
			if ( $payload->title !== '' ) {
				update_post_meta( $post_id, Native_Adapter::META_TITLE, $payload->title );
			}
			if ( $payload->description !== '' ) {
				update_post_meta( $post_id, Native_Adapter::META_DESC, $payload->description );
			}
			if ( $payload->focus_keyword !== '' ) {
				update_post_meta( $post_id, Native_Adapter::META_FOCUS, $payload->focus_keyword );
			}
		}

		$active->sync( $post_id, $payload );
	}

	/**
	 * Whether a third-party SEO plugin owns head output.
	 */
	public function third_party_owns_head(): bool {
		return $this->active_id() !== 'native';
	}
}
