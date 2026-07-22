<?php
/**
 * SEO audit issue codes, severities, and risk levels.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit;

final class Audit_Codes {

	public const SEVERITY_CRITICAL = 'critical';
	public const SEVERITY_HIGH     = 'high';
	public const SEVERITY_MEDIUM   = 'medium';
	public const SEVERITY_LOW      = 'low';

	public const RISK_SAFE      = 'safe';
	public const RISK_SENSITIVE = 'sensitive';

	public const STATUS_OPEN     = 'open';
	public const STATUS_IGNORED  = 'ignored';
	public const STATUS_FIXED    = 'fixed';
	public const STATUS_PENDING  = 'pending_fix';

	public const TITLE_MISSING       = 'title_missing';
	public const TITLE_TOO_SHORT     = 'title_too_short';
	public const TITLE_TOO_LONG      = 'title_too_long';
	public const DESC_MISSING        = 'meta_description_missing';
	public const DESC_TOO_SHORT      = 'meta_description_too_short';
	public const DESC_TOO_LONG       = 'meta_description_too_long';
	public const H1_MISSING          = 'h1_missing';
	public const H1_MULTIPLE         = 'h1_multiple';
	public const HEADING_SKIP        = 'heading_hierarchy_skip';
	public const IMAGE_ALT_MISSING   = 'image_alt_missing';
	public const FEATURED_MISSING    = 'featured_image_missing';
	public const BROKEN_LINK         = 'broken_link_404';
	public const CANONICAL_MISSING   = 'canonical_missing';
	public const CANONICAL_MISMATCH  = 'canonical_mismatch';
	public const ROBOTS_NOINDEX      = 'robots_noindex';
	public const SCHEMA_MISSING      = 'schema_missing';
	public const SITEMAP_MISSING     = 'sitemap_missing';
	public const MIXED_CONTENT       = 'mixed_content';
	public const THIN_CONTENT        = 'thin_content';
	public const INTERNAL_LINK_THIN  = 'internal_link_thin';
}
