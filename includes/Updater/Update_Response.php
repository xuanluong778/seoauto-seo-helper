<?php
/**
 * Normalized update check response from SEOAuto.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Updater;

final class Update_Response {

	public function __construct(
		public bool $update_available,
		public string $version = '',
		public string $package = '',
		public string $requires_wp = '6.0',
		public string $requires_php = '8.1',
		public string $tested = '6.7',
		public string $changelog_url = '',
		public string $sha256 = '',
		public string $release_signature = '',
		public string $channel = 'stable',
		public bool $autoupdate = false,
		public string $download_expires_at = '',
		public string $message = ''
	) {}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			! empty( $data['update_available'] ),
			(string) ( $data['version'] ?? '' ),
			(string) ( $data['package'] ?? $data['package_url'] ?? '' ),
			(string) ( $data['requires'] ?? $data['requires_wp'] ?? '6.0' ),
			(string) ( $data['requires_php'] ?? '8.1' ),
			(string) ( $data['tested'] ?? '6.7' ),
			(string) ( $data['changelog_url'] ?? '' ),
			strtolower( (string) ( $data['sha256'] ?? '' ) ),
			(string) ( $data['release_signature'] ?? '' ),
			sanitize_key( (string) ( $data['channel'] ?? 'stable' ) ),
			! empty( $data['autoupdate'] ),
			(string) ( $data['download_expires_at'] ?? '' ),
			(string) ( $data['message'] ?? '' )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'update_available'     => $this->update_available,
			'version'              => $this->version,
			'package'              => $this->package,
			'requires'             => $this->requires_wp,
			'requires_php'         => $this->requires_php,
			'tested'               => $this->tested,
			'changelog_url'        => $this->changelog_url,
			'sha256'               => $this->sha256,
			'release_signature'    => $this->release_signature,
			'channel'              => $this->channel,
			'autoupdate'           => $this->autoupdate,
			'download_expires_at'  => $this->download_expires_at,
			'message'              => $this->message,
		);
	}

	/**
	 * Shape expected by WordPress update_plugins_{host} filter / transient.
	 *
	 * @return object|false
	 */
	public function to_wp_update_object() {
		if ( ! $this->update_available || $this->version === '' || $this->package === '' ) {
			return false;
		}
		return (object) array(
			'id'            => 'seoauto-seo-helper/' . $this->version,
			'slug'          => 'seoauto-seo-helper',
			'plugin'        => SEOAUTO_HELPER_BASENAME,
			'new_version'   => $this->version,
			'url'           => $this->changelog_url !== '' ? $this->changelog_url : 'https://seoauto.vn',
			'package'       => $this->package,
			'icons'         => array(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'tested'        => $this->tested,
			'requires'      => $this->requires_wp,
			'requires_php'  => $this->requires_php,
		);
	}
}
