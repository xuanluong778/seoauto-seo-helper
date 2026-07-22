<?php
/**
 * Deactivation routines.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper;

use SEOAuto\SEOHelper\Cron\Cron_Scheduler;

final class Deactivator {

	/**
	 * Clears scheduled events. Keeps options (pairing) until uninstall.
	 * Does not disable other security plugins.
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( Cron_Scheduler::HOOK_SYNC );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, Cron_Scheduler::HOOK_SYNC );
		}
		wp_clear_scheduled_hook( Cron_Scheduler::HOOK_SYNC );
		// String hook — avoid hard dependency when Deactivator loads alone in tests.
		wp_clear_scheduled_hook( 'seoauto_helper_process_audit_jobs' );
		flush_rewrite_rules( false );
	}
}
