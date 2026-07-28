<?php

namespace SmartCloud\AgentComposer\Domain\Configuration;

use RuntimeException;

final class ConfigurationConflict extends RuntimeException {
	public function __construct( private readonly array $details ) {
		parent::__construct( 'The configuration entity changed after it was loaded.' );
	}

	public function details(): array {
		return $this->details;
	}
}
