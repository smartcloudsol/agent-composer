<?php

namespace SmartCloud\AgentComposer\Security;

final class ActorIdentity {
	private static ?ActorContext $context = null;

	public static function set( ?ActorContext $context ): void {
		self::$context = $context;
	}

	public static function context(): ?ActorContext {
		return self::$context;
	}

	public static function principal_id(): string {
		if ( self::$context ) {
			return self::$context->principal_id();
		}
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		return $user_id > 0 ? 'wp-user:' . $user_id : 'open:anonymous';
	}

	public static function is_current( string $principal_id, int $legacy_user_id = 0 ): bool {
		if ( '' !== $principal_id ) {
			return hash_equals( $principal_id, self::principal_id() );
		}
		return $legacy_user_id > 0 && function_exists( 'get_current_user_id' ) && get_current_user_id() === $legacy_user_id;
	}

	private function __construct() {}
}
