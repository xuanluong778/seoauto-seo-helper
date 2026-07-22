<?php
/**
 * @deprecated 1.0.0 Schema is rendered by Native_Adapter only when no SEO plugin is active.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Seo;

final class Schema_Adapter {

	public function render(): void {
		// Intentionally empty — prevents duplicate JSON-LD when SEO plugins own schema.
	}
}
