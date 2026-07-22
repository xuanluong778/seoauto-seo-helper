<?php
/**
 * Checker contract.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit;

interface Checker_Interface {

	public function id(): string;

	/**
	 * @return list<Audit_Issue>
	 */
	public function check( Object_Context $ctx ): array;
}
