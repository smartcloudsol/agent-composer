<?php
/* Generated from SmartCloud Agent Composer execution baseline 0.6.8 by the frozen Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

use RuntimeException;

final class Execution_Exception extends RuntimeException {
	private string $execution_code;

	/**
	 * @param string $execution_code Stable machine-readable error code.
	 * @param string $message Human-readable error message.
	 * @param int    $code Optional native exception code.
	 */
	public function __construct( string $execution_code, string $message, int $code = 0 ) {
		parent::__construct( $message, $code );
		$this->execution_code = $execution_code;
	}

	public function get_execution_code(): string {
		return $this->execution_code;
	}
}
