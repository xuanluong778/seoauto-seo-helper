<?php

/**

 * Cron — entitlement re-check every 6 hours.

 *

 * @package SEOAuto\SEOHelper

 */



declare(strict_types=1);



namespace SEOAuto\SEOHelper\Cron;



use SEOAuto\SEOHelper\Audit\Audit_Logger;

use SEOAuto\SEOHelper\Connection\Connection_Manager;

use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;



final class Cron_Scheduler {



	public const HOOK_SYNC     = 'seoauto_helper_sync_entitlement';

	public const SCHEDULE      = 'seoauto_six_hours';



	public function __construct(

		private Connection_Manager $connection,

		private Entitlement_Manager $entitlement,

		private Audit_Logger $audit

	) {}



	public function register(): void {

		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );

		add_action( self::HOOK_SYNC, array( $this, 'sync_entitlement' ) );

		$this->ensure_scheduled();

	}



	/**

	 * @param array<string,array<string,mixed>> $schedules

	 * @return array<string,array<string,mixed>>

	 */

	public function add_schedules( array $schedules ): array {

		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {

			$schedules[ self::SCHEDULE ] = array(

				'interval' => 6 * \HOUR_IN_SECONDS,

				'display'  => __( 'Mỗi 6 giờ (SEOAuto)', 'seoauto-seo-helper' ),

			);

		}

		return $schedules;

	}



	public function ensure_scheduled(): void {

		if ( ! wp_next_scheduled( self::HOOK_SYNC ) ) {

			wp_schedule_event( time() + \HOUR_IN_SECONDS, self::SCHEDULE, self::HOOK_SYNC );

		}

	}



	/**

	 * Re-evaluate cached entitlement and apply LOCKED / unlock.

	 * Does not delete content or deactivate the plugin.

	 */

	public function sync_entitlement(): void {

		if ( ! $this->connection->has_credentials() ) {

			return;

		}



		$result = $this->entitlement->refresh_check( 'cron' );

		$this->audit->purge_expired();



		// Audit skipped automatically when locked (should_log_audit).

		$this->audit->log(

			'cron_sync',

			array(

				'allowed'     => $result['allowed'],

				'locked'      => $result['locked'],

				'lock_reason' => $result['reason'],

				'site_id'     => $this->connection->site_id(),

			)

		);

	}

}


