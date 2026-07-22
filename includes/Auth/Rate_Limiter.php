<?php
/**
 * Simple per-site rate limiter using transients.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Auth;

final class Rate_Limiter {

	private int $limit;
	private int $window;

	public function __construct( int $limit_per_window = 60, int $window_seconds = 60 ) {
		$this->limit  = max( 1, $limit_per_window );
		$this->window = max( 10, $window_seconds );
	}

	/**
	 * @return true|\WP_Error
	 */
	public function attempt( string $bucket_key ): bool|\WP_Error {
		$key  = SEOAUTO_HELPER_PREFIX . 'rl_' . md5( $bucket_key );
		$now  = time();
		$data = get_transient( $key );
		if ( ! is_array( $data ) || ! isset( $data['start'], $data['count'] ) ) {
			$data = array(
				'start' => $now,
				'count' => 0,
			);
		}
		if ( ( $now - (int) $data['start'] ) >= $this->window ) {
			$data = array(
				'start' => $now,
				'count' => 0,
			);
		}
		$data['count'] = (int) $data['count'] + 1;
		set_transient( $key, $data, $this->window );

		if ( (int) $data['count'] > $this->limit ) {
			return new \WP_Error(
				'seoauto_rate_limited',
				__( 'Quá nhiều request từ SEOAuto. Thử lại sau.', 'seoauto-seo-helper' ),
				array( 'status' => 429 )
			);
		}
		return true;
	}
}
