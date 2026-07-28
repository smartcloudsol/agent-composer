<?php

namespace SmartCloud\AgentComposer\Application\Configuration;

use InvalidArgumentException;
use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;

final class ValidationReceiptService {
	private const TRANSIENT_PREFIX = 'smartcloud_composer_validation_';
	private const TTL              = 900;

	public function issue( string $config_set, string $config_hash ): array {
		$payload = array(
			'config_set'  => sanitize_key( $config_set ),
			'config_hash' => $config_hash,
			'site_id'     => get_current_blog_id(),
			'user_id'     => get_current_user_id(),
			'issued_gmt'  => gmdate( 'c' ),
			'expires_gmt' => gmdate( 'c', time() + self::TTL ),
			'nonce'       => wp_generate_uuid4(),
		);
		$receipt = CanonicalJson::checksum( $payload );
		set_transient( $this->transient_key( $receipt ), $payload, self::TTL );
		return array( 'receipt' => $receipt, 'payload' => $payload );
	}

	public function assert_valid( string $receipt, string $config_set, string $config_hash ): array {
		$payload = get_transient( $this->transient_key( $receipt ) );
		if (
			! is_array( $payload )
			|| ! hash_equals( CanonicalJson::checksum( $payload ), $receipt )
			|| sanitize_key( $config_set ) !== ( $payload['config_set'] ?? '' )
			|| ! hash_equals( $config_hash, (string) ( $payload['config_hash'] ?? '' ) )
			|| get_current_blog_id() !== (int) ( $payload['site_id'] ?? 0 )
			|| get_current_user_id() !== (int) ( $payload['user_id'] ?? 0 )
			|| strtotime( (string) ( $payload['expires_gmt'] ?? '' ) ) < time()
		) {
			throw new InvalidArgumentException( 'The validation receipt is missing, expired, or does not match this site, user, and configuration checksum.' );
		}
		return $payload;
	}

	public function consume( string $receipt ): void {
		delete_transient( $this->transient_key( $receipt ) );
	}

	private function transient_key( string $receipt ): string {
		return self::TRANSIENT_PREFIX . substr( preg_replace( '/[^a-f0-9]/', '', strtolower( $receipt ) ), 0, 64 );
	}
}
