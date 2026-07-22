<?php
/**
 * Minimal dbDelta stub for offline lifecycle tests.
 */

if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( string $sql ): void {
		// No-op: schema DDL is validated via Activator/Schema unit flow only.
	}
}
