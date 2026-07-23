<?php
/**
 * Cron: ContentOps retention purge (daily) + expired locks.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\ContentOps;

final class ContentOps_Cron {

	public const HOOK_PURGE = 'seoauto_helper_content_ops_purge';
	public const SCHEDULE   = 'daily';

	public function __construct( private ContentOps_Service $ops ) {}

	public function register(): void {
		add_action( self::HOOK_PURGE, array( $this, 'run_purge' ) );
		$this->ensure_scheduled();
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK_PURGE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, self::SCHEDULE, self::HOOK_PURGE );
		}
	}

	public function run_purge(): void {
		$this->ops->purge_expired();
	}

	public static function clear(): void {
		wp_clear_scheduled_hook( self::HOOK_PURGE );
	}
}
