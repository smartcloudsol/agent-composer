<?php

namespace SmartCloud\AgentComposer\Domain\Configuration;

use InvalidArgumentException;

final class EntityType {
	public const CONFIG_SET      = 'config-set';
	public const SITE_CONTRACT   = 'site-contract';
	public const COMPONENT       = 'component';
	public const STYLE_MAPPING   = 'style-mapping';
	public const BLUEPRINT       = 'blueprint';
	public const PROVIDER_POLICY = 'provider-policy';
	public const DISCOVERY       = 'discovery';

	public static function all(): array {
		return array(
			self::CONFIG_SET,
			self::SITE_CONTRACT,
			self::COMPONENT,
			self::STYLE_MAPPING,
			self::BLUEPRINT,
			self::PROVIDER_POLICY,
			self::DISCOVERY,
		);
	}

	public static function assert( string $type ): string {
		if ( ! in_array( $type, self::all(), true ) ) {
			throw new InvalidArgumentException( 'Unsupported Composer entity type.' );
		}
		return $type;
	}

	private function __construct() {}
}
