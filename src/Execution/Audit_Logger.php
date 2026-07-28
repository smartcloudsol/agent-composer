<?php

namespace SmartCloud\AgentComposer\Execution;

use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;

final class Audit_Logger {
	private string $request_id;

	public function __construct( private readonly AuditTable $audit ) {
		$this->begin_operation();
	}

	public function log(
		string $operation,
		string $outcome,
		array $input = array(),
		int $object_id = 0,
		string $error_code = '',
		array $context = array()
	): void {
		$this->audit->record(
			$operation,
			in_array( $outcome, array( 'success', 'error', 'denied' ), true ) ? $outcome : 'error',
			array(
				'request_id' => $this->request_id,
				'input_hash' => CanonicalJson::checksum( $input ),
				'error_code' => sanitize_key( $error_code ),
				'context'    => $this->safe_context( $context ),
			),
			0,
			max( 0, $object_id )
		);
	}

	public function get_request_id(): string {
		return $this->request_id;
	}

	public function begin_operation(): string {
		$this->request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		return $this->request_id;
	}

	private function safe_context( array $context ): array {
		$allowed = array( 'page_type', 'pattern_count', 'validation_valid', 'conflict' );
		return array_intersect_key( $context, array_flip( $allowed ) );
	}
}
