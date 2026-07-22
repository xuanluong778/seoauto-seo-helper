<?php
/**
 * @deprecated 1.0.0 Open Graph is rendered by Native_Adapter only when no SEO plugin is active.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Seo;

final class Open_Graph_Adapter {

	public function render(): void {
		// Intentionally empty — prevents duplicate OG when SEO plugins own <head>.
	}
}
