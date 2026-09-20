<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

use RuntimeException;

final class Execution_Exception extends RuntimeException {
	private string $execution_code;
	private array $execution_data;

	/**
	 * @param string $execution_code Stable machine-readable error code.
	 * @param string $message Human-readable error message.
	 * @param int    $code Optional native exception code.
	 * @param array  $data Safe machine-readable error details.
	 */
	public function __construct( string $execution_code, string $message, int $code = 0, array $data = array() ) {
		parent::__construct( $message, $code );
		$this->execution_code = $execution_code;
		$this->execution_data = $data;
	}

	public function get_execution_code(): string {
		return $this->execution_code;
	}

	public function get_execution_data(): array {
		return $this->execution_data;
	}
}
