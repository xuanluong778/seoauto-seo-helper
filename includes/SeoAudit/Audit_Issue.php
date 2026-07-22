<?php
/**
 * SEO audit finding DTO.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit;

final class Audit_Issue {

	public function __construct(
		public string $issue_code,
		public string $severity,
		public string $risk_level,
		public string $object_type = 'post',
		public int $object_id = 0,
		public string $current_value = '',
		public string $suggested_value = '',
		public string $message = '',
		public string $status = Audit_Codes::STATUS_OPEN,
		/** @var array<string,mixed> */
		public array $context = array(),
		public int $id = 0,
		public int $run_id = 0
	) {}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'run_id'          => $this->run_id,
			'object_type'     => $this->object_type,
			'object_id'       => $this->object_id,
			'issue_code'      => $this->issue_code,
			'severity'        => $this->severity,
			'risk_level'      => $this->risk_level,
			'status'          => $this->status,
			'current_value'   => $this->current_value,
			'suggested_value' => $this->suggested_value,
			'message'         => $this->message,
			'context'         => $this->context,
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function from_row( array $row ): self {
		$ctx = array();
		if ( ! empty( $row['context_json'] ) ) {
			$decoded = json_decode( (string) $row['context_json'], true );
			$ctx     = is_array( $decoded ) ? $decoded : array();
		}
		return new self(
			(string) ( $row['issue_code'] ?? '' ),
			(string) ( $row['severity'] ?? Audit_Codes::SEVERITY_MEDIUM ),
			(string) ( $row['risk_level'] ?? Audit_Codes::RISK_SAFE ),
			(string) ( $row['object_type'] ?? 'post' ),
			(int) ( $row['object_id'] ?? 0 ),
			(string) ( $row['current_value'] ?? '' ),
			(string) ( $row['suggested_value'] ?? '' ),
			(string) ( $row['message'] ?? '' ),
			(string) ( $row['status'] ?? Audit_Codes::STATUS_OPEN ),
			$ctx,
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['run_id'] ?? 0 )
		);
	}
}
