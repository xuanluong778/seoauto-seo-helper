<?php
/**
 * One-time nonce store (replay protection).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Auth;

final class Nonce_Store {

	private const TTL = 600; // 10 minutes > skew window
	private const MAX = 500;

	private function option_key(): string {
		return SEOAUTO_HELPER_PREFIX . 'used_nonces';
	}

	/**
	 * Returns true if nonce is fresh and was recorded; false if already used.
	 */
	public function claim( string $nonce ): bool {
		$nonce = trim( $nonce );
		if ( $nonce === '' || strlen( $nonce ) > 128 ) {
			return false;
		}

		$now  = time();
		$rows = $this->load();
		$rows = $this->prune( $rows, $now );

		if ( isset( $rows[ $nonce ] ) ) {
			$this->save( $rows );
			return false;
		}

		$rows[ $nonce ] = $now + self::TTL;
		if ( count( $rows ) > self::MAX ) {
			asort( $rows );
			$rows = array_slice( $rows, -self::MAX, null, true );
		}
		$this->save( $rows );
		return true;
	}

	/**
	 * @return array<string,int>
	 */
	private function load(): array {
		$raw = get_option( $this->option_key(), array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $k => $v ) {
			$out[ (string) $k ] = (int) $v;
		}
		return $out;
	}

	/**
	 * @param array<string,int> $rows
	 * @return array<string,int>
	 */
	private function prune( array $rows, int $now ): array {
		foreach ( $rows as $k => $exp ) {
			if ( (int) $exp < $now ) {
				unset( $rows[ $k ] );
			}
		}
		return $rows;
	}

	/**
	 * @param array<string,int> $rows
	 */
	private function save( array $rows ): void {
		if ( false === get_option( $this->option_key(), false ) && ! $this->exists() ) {
			add_option( $this->option_key(), $rows, '', false );
			return;
		}
		update_option( $this->option_key(), $rows, false );
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$wpdb->update(
				$wpdb->options,
				array( 'autoload' => 'no' ),
				array( 'option_name' => $this->option_key() ),
				array( '%s' ),
				array( '%s' )
			);
		}
	}

	private function exists(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$this->option_key()
			)
		);
		return null !== $id && '' !== (string) $id;
	}
}
