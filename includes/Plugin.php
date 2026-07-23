<?php
/**
 * Main plugin orchestrator.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper;

use SEOAuto\SEOHelper\Admin\Admin_Menu;
use SEOAuto\SEOHelper\Audit\Audit_Logger;
use SEOAuto\SEOHelper\Auth\Request_Authenticator;
use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\ContentOps\ContentOps_Cron;
use SEOAuto\SEOHelper\ContentOps\ContentOps_Service;
use SEOAuto\SEOHelper\Cron\Cron_Scheduler;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Client;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;
use SEOAuto\SEOHelper\Media\Media_Service;
use SEOAuto\SEOHelper\Post\Post_Service;
use SEOAuto\SEOHelper\Post\Publishing_Settings;
use SEOAuto\SEOHelper\Rest\Audit_Rest_Controller;
use SEOAuto\SEOHelper\Rest\ContentOps_Rest_Controller;
use SEOAuto\SEOHelper\Rest\Rest_Controller;
use SEOAuto\SEOHelper\Seo\Seo_Facade;
use SEOAuto\SEOHelper\SeoAudit\Audit_Job_Runner;
use SEOAuto\SEOHelper\Updater\Update_Admin;
use SEOAuto\SEOHelper\Updater\Update_Manager;

final class Plugin {

	private static ?self $instance = null;

	private Connection_Manager $connection;
	private Entitlement_Manager $entitlement;
	private Audit_Logger $audit;
	private Request_Authenticator $auth;
	private Publishing_Settings $publishing;
	private Post_Service $posts;
	private Media_Service $media;
	private Seo_Facade $seo;
	private Cron_Scheduler $cron;
	private Audit_Job_Runner $audit_jobs;
	private Update_Manager $updater;
	private ContentOps_Service $content_ops;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->connection  = new Connection_Manager();
		$this->entitlement = new Entitlement_Manager(
			$this->connection,
			new Entitlement_Client( $this->connection )
		);
		$this->audit       = new Audit_Logger( $this->entitlement );
		$this->auth        = new Request_Authenticator( $this->connection, $this->entitlement );
		$this->publishing  = new Publishing_Settings();
		$this->media       = new Media_Service( $this->audit, $this->connection );
		$this->posts       = new Post_Service(
			$this->audit,
			$this->connection,
			$this->publishing,
			$this->media,
			new \SEOAuto\SEOHelper\Post\Idempotency_Store()
		);
		$this->seo         = new Seo_Facade();
		$this->cron        = new Cron_Scheduler( $this->connection, $this->entitlement, $this->audit );
		$this->audit_jobs  = new Audit_Job_Runner(
			$this->entitlement,
			$this->connection,
			$this->seo,
			$this->audit
		);
		$this->updater     = new Update_Manager(
			$this->connection,
			$this->entitlement,
			$this->audit
		);
		$this->content_ops = new ContentOps_Service(
			$this->connection,
			$this->entitlement,
			$this->seo,
			$this->audit
		);
	}

	public function boot(): void {
		load_plugin_textdomain( 'seoauto-seo-helper', false, dirname( SEOAUTO_HELPER_BASENAME ) . '/languages' );

		( new Admin_Menu(
			$this->connection,
			$this->entitlement,
			$this->audit,
			$this->publishing,
			$this->seo,
			$this->audit_jobs,
			$this->content_ops
		) )->register();
		( new Rest_Controller(
			$this->connection,
			$this->entitlement,
			$this->auth,
			$this->posts,
			$this->media,
			$this->seo,
			$this->audit,
			$this->publishing
		) )->register();
		( new Audit_Rest_Controller( $this->auth, $this->audit_jobs ) )->register();
		( new ContentOps_Rest_Controller( $this->auth, $this->content_ops ) )->register();

		$this->seo->register_hooks();
		$this->cron->register();
		$this->audit_jobs->register();
		$this->updater->register();
		( new Update_Admin( $this->updater ) )->register();
		( new ContentOps_Cron( $this->content_ops ) )->register();

		\SEOAuto\SEOHelper\Post\Schema::maybe_upgrade();
	}

	public function connection(): Connection_Manager {
		return $this->connection;
	}

	public function entitlement(): Entitlement_Manager {
		return $this->entitlement;
	}

	public function audit(): Audit_Logger {
		return $this->audit;
	}

	public function audit_jobs(): Audit_Job_Runner {
		return $this->audit_jobs;
	}

	public function updater(): Update_Manager {
		return $this->updater;
	}

	public function seo(): Seo_Facade {
		return $this->seo;
	}

	public function content_ops(): ContentOps_Service {
		return $this->content_ops;
	}
}
