<?php
/**
 * Contract for Rank Math / Yoast / AIOSEO / native adapters.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Seo;

interface Seo_Adapter_Interface {

	public function id(): string;

	public function is_active(): bool;

	/**
	 * Write SEO fields via this adapter only (no duplicate head tags).
	 */
	public function sync( int $post_id, Seo_Payload $payload ): void;
}
