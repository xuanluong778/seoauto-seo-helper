<?php
/**
 * Private plugin updater — wires WordPress update UI via Update URI host filter.
 *
 * LOCKED sites still receive security patches (update check does not require
 * paid features). Does not modify Billing / Credit / HMAC crypto.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Updater;

use SEOAuto\SEOHelper\Audit\Audit_Logger;
use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;
use WP_Error;

final class Update_Manager {

	public const CACHE_OPTION   = 'update_check_cache';
	public const CACHE_TTL      = 6 * \HOUR_IN_SECONDS;
	public const CHANNEL_OPTION = 'update_channel';
	public const HOST_FILTER    = 'update_plugins_seoauto.vn';

	private Update_Client $client;
	private Package_Verifier $verifier;

	public function __construct(
		private Connection_Manager $connection,
		private Entitlement_Manager $entitlement,
		private Audit_Logger $audit,
		?Update_Client $client = null,
		?Package_Verifier $verifier = null
	) {
		$this->client   = $client ?? new Update_Client( $this->connection );
		$this->verifier = $verifier ?? new Package_Verifier();
	}

	public function register(): void {
		add_filter( self::HOST_FILTER, array( $this, 'filter_update' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( $this, 'auto_update_plugin' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 10, 2 );
	}

	/**
	 * @param false|object|array $update
	 * @param array<string,mixed> $plugin_data
	 * @param string $plugin_file
	 * @param string[] $locales
	 * @return false|object
	 */
	public function filter_update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( SEOAUTO_HELPER_BASENAME !== (string) $plugin_file ) {
			return $update;
		}

		$response = $this->get_cached_or_fetch( false );
		if ( $response instanceof WP_Error || ! $response->update_available ) {
			return false;
		}

		$newer = $this->verifier->assert_newer_version( SEOAUTO_HELPER_VERSION, $response->version );
		if ( $newer instanceof WP_Error ) {
			return false;
		}
		$url_ok = $this->verifier->assert_safe_package_url( $response->package );
		if ( $url_ok instanceof WP_Error ) {
			return false;
		}

		$obj = $response->to_wp_update_object();
		return $obj ? $obj : false;
	}

	/**
	 * Plugin details modal ("View details").
	 *
	 * @param false|object|array $result
	 * @param string $action
	 * @param object $args
	 * @return false|object|array
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		$slug = is_object( $args ) ? (string) ( $args->slug ?? '' ) : '';
		if ( $slug !== 'seoauto-seo-helper' ) {
			return $result;
		}

		$response = $this->get_cached_or_fetch( false );
		$version  = $response instanceof Update_Response && $response->version !== ''
			? $response->version
			: SEOAUTO_HELPER_VERSION;
		$changelog = $response instanceof Update_Response ? $response->changelog_url : 'https://seoauto.vn';

		return (object) array(
			'name'          => 'SEOAuto SEO Helper',
			'slug'          => 'seoauto-seo-helper',
			'version'       => $version,
			'author'        => '<a href="https://seoauto.vn">SEOAuto</a>',
			'homepage'      => 'https://seoauto.vn',
			'requires'      => $response instanceof Update_Response ? $response->requires_wp : '6.0',
			'requires_php'  => $response instanceof Update_Response ? $response->requires_php : '8.1',
			'tested'        => $response instanceof Update_Response ? $response->tested : '6.7',
			'sections'      => array(
				'description' => __( 'Kết nối WordPress với SEOAuto — SEO meta, publish, SEO Audit.', 'seoauto-seo-helper' ),
				'changelog'   => sprintf(
					'<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
					esc_url( $changelog !== '' ? $changelog : 'https://seoauto.vn' ),
					esc_html__( 'Xem changelog trên SEOAuto', 'seoauto-seo-helper' )
				),
			),
			'download_link' => $response instanceof Update_Response ? $response->package : '',
		);
	}

	/**
	 * Download + verify package before WP extracts it.
	 *
	 * @param bool|string|\WP_Error $reply
	 * @param string $package
	 * @param \WP_Upgrader $upgrader
	 * @return bool|string|\WP_Error
	 */
	public function pre_download( $reply, $package, $upgrader ) {
		$package = (string) $package;
		if ( $package === '' || ! $this->is_our_package_url( $package ) ) {
			return $reply;
		}

		$url_ok = $this->verifier->assert_safe_package_url( $package );
		if ( $url_ok instanceof WP_Error ) {
			return $url_ok;
		}

		$cached = $this->read_cache();
		$meta   = $cached instanceof Update_Response ? $cached : null;

		$tmp = download_url( $package, 60 );
		if ( is_wp_error( $tmp ) ) {
			$this->audit->log_error(
				'plugin_update_download',
				'seoauto_update_download',
				array( 'status' => 'error' )
			);
			return $tmp;
		}

		if ( $meta && $meta->sha256 !== '' ) {
			$sha = $this->verifier->assert_sha256_file( $tmp, $meta->sha256 );
			if ( $sha instanceof WP_Error ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$this->audit->log_error( 'plugin_update_checksum', $sha->get_error_code(), array( 'status' => 'error' ) );
				return $sha;
			}
		}

		if ( $meta && $meta->release_signature !== '' ) {
			$sig = $this->verifier->assert_release_signature(
				$meta->release_signature,
				$this->connection->site_secret(),
				$meta->version,
				$meta->sha256,
				$meta->download_expires_at
			);
			if ( $sig instanceof WP_Error ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$this->audit->log_error( 'plugin_update_signature', $sig->get_error_code(), array( 'status' => 'error' ) );
				return $sig;
			}
		}

		$zip = $this->verifier->assert_zip_structure( $tmp );
		if ( $zip instanceof WP_Error ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->audit->log_error( 'plugin_update_zip', $zip->get_error_code(), array( 'status' => 'error' ) );
			return $zip;
		}

		$this->audit->log(
			'plugin_update_download_ok',
			array(
				'version' => $meta ? $meta->version : '',
				'status'  => 'ok',
			)
		);

		return $tmp;
	}

	/**
	 * @param bool   $update
	 * @param object $item
	 */
	public function auto_update_plugin( $update, $item ): bool {
		$plugin = is_object( $item ) ? (string) ( $item->plugin ?? '' ) : '';
		if ( $plugin !== SEOAUTO_HELPER_BASENAME ) {
			return (bool) $update;
		}
		$cached = $this->read_cache();
		if ( $cached instanceof Update_Response && $cached->autoupdate ) {
			return true;
		}
		return false;
	}

	/**
	 * @param \WP_Upgrader $upgrader
	 * @param array<string,mixed> $options
	 */
	public function after_upgrade( $upgrader, $options ): void {
		if ( ( $options['action'] ?? '' ) !== 'update' || ( $options['type'] ?? '' ) !== 'plugin' ) {
			return;
		}
		$plugins = $options['plugins'] ?? array();
		if ( ! is_array( $plugins ) || ! in_array( SEOAUTO_HELPER_BASENAME, $plugins, true ) ) {
			return;
		}
		$this->clear_cache();
		// Migration must also run on next boot via Schema::maybe_upgrade().
		if ( class_exists( '\\SEOAuto\\SEOHelper\\Post\\Schema' ) ) {
			\SEOAuto\SEOHelper\Post\Schema::maybe_upgrade();
		}
		$this->audit->log( 'plugin_update_complete', array( 'status' => 'ok', 'to' => SEOAUTO_HELPER_VERSION ) );
	}

	/**
	 * Force refresh (admin "Check for updates").
	 *
	 * @return Update_Response|WP_Error
	 */
	public function force_check() {
		$this->clear_cache();
		return $this->get_cached_or_fetch( true );
	}

	public function clear_cache(): void {
		delete_option( SEOAUTO_HELPER_PREFIX . self::CACHE_OPTION );
		delete_site_transient( 'update_plugins' );
	}

	public function channel(): string {
		return self::resolve_channel(
			(string) $this->connection->option( self::CHANNEL_OPTION, '' ),
			defined( 'SEOAUTO_HELPER_VERSION' ) ? (string) SEOAUTO_HELPER_VERSION : '0'
		);
	}

	/**
	 * Explicit option wins; otherwise pre-release builds check the beta channel
	 * so RC canaries can see newer RCs without a manual channel toggle.
	 */
	public static function resolve_channel( string $stored, string $plugin_version ): string {
		$ch = sanitize_key( $stored );
		if ( in_array( $ch, array( 'stable', 'beta' ), true ) ) {
			return $ch;
		}
		return self::is_prerelease_version( $plugin_version ) ? 'beta' : 'stable';
	}

	public static function is_prerelease_version( string $version ): bool {
		return (bool) preg_match( '/-(?:rc|beta|alpha)(?:\.|$)/i', $version );
	}

	/**
	 * @return Update_Response|WP_Error|null
	 */
	public function read_cache() {
		$raw = $this->connection->option( self::CACHE_OPTION, '' );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$checked = (int) ( $data['checked_at'] ?? 0 );
		if ( $checked > 0 && ( time() - $checked ) > self::CACHE_TTL ) {
			return null;
		}
		$payload = is_array( $data['response'] ?? null ) ? $data['response'] : array();
		$resp    = Update_Response::from_array( $payload );
		// Short-lived package URL: force re-check when expired (keep 6h metadata only while URL valid).
		if ( $resp->download_expires_at !== '' ) {
			$ts = strtotime( $resp->download_expires_at );
			if ( false !== $ts && time() > $ts ) {
				return null;
			}
		}
		return $resp;
	}

	/**
	 * @return Update_Response|WP_Error
	 */
	private function get_cached_or_fetch( bool $force ) {
		if ( ! $force ) {
			$cached = $this->read_cache();
			if ( $cached instanceof Update_Response ) {
				return $cached;
			}
		}

		// LOCKED still allowed — security patches independent of paid features.
		$result = $this->client->check( $this->channel() );
		if ( $result instanceof WP_Error ) {
			$this->audit->log_error(
				'plugin_update_check',
				$result->get_error_code(),
				array( 'status' => 'error', 'locked' => $this->entitlement->is_locked() )
			);
			return $result;
		}

		// Strip package from durable cache log; store for WP update transient only in option.
		$store = $result->to_array();
		$this->connection->update_option(
			self::CACHE_OPTION,
			wp_json_encode(
				array(
					'checked_at' => time(),
					'response'   => $store,
				)
			)
		);

		$this->audit->log(
			'plugin_update_check',
			array(
				'status'            => 'ok',
				'update_available'  => $result->update_available,
				'version'           => $result->version,
				'channel'           => $result->channel,
				'locked'            => $this->entitlement->is_locked(),
			)
		);

		return $result;
	}

	private function is_our_package_url( string $url ): bool {
		$host = strtolower( (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?? '' ) );
		$path = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '' );
		if ( $host === '' ) {
			return false;
		}
		$allowed = apply_filters( 'seoauto_helper_update_allowed_hosts', array( 'seoauto.vn', 'www.seoauto.vn', 'cdn.seoauto.vn', 'downloads.seoauto.vn' ) );
		if ( ! is_array( $allowed ) || ! in_array( $host, array_map( 'strtolower', $allowed ), true ) ) {
			return false;
		}
		return str_contains( $path, '/wordpress-plugin/updates/' ) || str_contains( $path, 'seoauto-seo-helper' );
	}
}
