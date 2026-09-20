<?php

namespace SmartCloud\AgentComposer\Security;

final class CognitoJwtValidator {
	private const CLOCK_SKEW = 60;

	/** @return array<string,mixed>|\WP_Error */
	public function validate( string $token, array $configuration, string $expected_audience = '' ): array|\WP_Error {
		$parts = explode( '.', trim( $token ) );
		if ( 3 !== count( $parts ) || strlen( $token ) > 16384 ) {
			return $this->error( 'malformed_token', 'The Cognito access token is malformed.', 401 );
		}
		$header = $this->decode_json( $parts[0] );
		$claims = $this->decode_json( $parts[1] );
		$signature = $this->decode_segment( $parts[2] );
		if ( ! is_array( $header ) || ! is_array( $claims ) || false === $signature ) {
			return $this->error( 'malformed_token', 'The Cognito access token could not be decoded.', 401 );
		}
		if ( 'RS256' !== ( $header['alg'] ?? '' ) ) {
			return $this->error( 'unsupported_token_algorithm', 'Only Cognito RS256 access tokens are accepted.', 401 );
		}
		$kid = (string) ( $header['kid'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9_\-+=\/]{1,256}$/', $kid ) ) {
			return $this->error( 'invalid_token_key', 'The Cognito token key identifier is invalid.', 401 );
		}

		$region = (string) ( $configuration['region'] ?? '' );
		$pool   = (string) ( $configuration['user_pool_id'] ?? '' );
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Cognito issuer discovery is the configured authentication boundary, not a plugin asset CDN.
		$issuer = sprintf( 'https://cognito-idp.%s.amazonaws.com/%s', $region, $pool );
		if ( ! hash_equals( $issuer, (string) ( $claims['iss'] ?? '' ) ) ) {
			return $this->error( 'token_issuer_mismatch', 'The access token was issued by a different Cognito User Pool.', 401 );
		}
		if ( 'access' !== ( $claims['token_use'] ?? '' ) ) {
			return $this->error( 'wrong_token_use', 'A Cognito access token is required; ID tokens are not accepted.', 401 );
		}
		if ( '' !== $expected_audience && ! $this->audience_matches( $claims['aud'] ?? null, $expected_audience ) ) {
			return $this->error( 'token_audience_mismatch', 'The access token was not issued for this Composer MCP resource.', 401 );
		}
		$now = time();
		if ( (int) ( $claims['exp'] ?? 0 ) < $now - self::CLOCK_SKEW ) {
			return $this->error( 'token_expired', 'The Cognito access token has expired.', 401 );
		}
		if ( isset( $claims['nbf'] ) && (int) $claims['nbf'] > $now + self::CLOCK_SKEW ) {
			return $this->error( 'token_not_yet_valid', 'The Cognito access token is not valid yet.', 401 );
		}
		if ( isset( $claims['iat'] ) && (int) $claims['iat'] > $now + self::CLOCK_SKEW ) {
			return $this->error( 'token_issued_in_future', 'The Cognito access token issue time is invalid.', 401 );
		}
		$subject = trim( (string) ( $claims['sub'] ?? '' ) );
		$client  = trim( (string) ( $claims['client_id'] ?? '' ) );
		if ( '' === $subject || strlen( $subject ) > 256 || '' === $client || strlen( $client ) > 256 ) {
			return $this->error( 'token_claims_missing', 'The Cognito access token is missing required subject or client claims.', 401 );
		}

		$jwks = $this->jwks( $issuer );
		if ( is_wp_error( $jwks ) ) { return $jwks; }
		$jwk = null;
		foreach ( (array) ( $jwks['keys'] ?? array() ) as $candidate ) {
			if ( is_array( $candidate ) && hash_equals( $kid, (string) ( $candidate['kid'] ?? '' ) ) ) { $jwk = $candidate; break; }
		}
		if ( ! is_array( $jwk ) ) {
			delete_transient( $this->cache_key( $issuer ) );
			$jwks = $this->jwks( $issuer );
			if ( is_wp_error( $jwks ) ) { return $jwks; }
			foreach ( (array) ( $jwks['keys'] ?? array() ) as $candidate ) {
				if ( is_array( $candidate ) && hash_equals( $kid, (string) ( $candidate['kid'] ?? '' ) ) ) { $jwk = $candidate; break; }
			}
		}
		if ( ! is_array( $jwk ) ) {
			return $this->error( 'token_key_not_found', 'The Cognito signing key was not found.', 401 );
		}
		$pem = $this->jwk_to_pem( $jwk );
		if ( is_wp_error( $pem ) ) { return $pem; }
		$verified = openssl_verify( $parts[0] . '.' . $parts[1], $signature, $pem, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $verified ) {
			return $this->error( 'invalid_token_signature', 'The Cognito access-token signature is invalid.', 401 );
		}
		return $claims;
	}

	private function jwks( string $issuer ): array|\WP_Error {
		$key = $this->cache_key( $issuer );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['keys'] ) ) { return $cached; }
		$response = wp_safe_remote_get(
			$issuer . '/.well-known/jwks.json',
			array( 'timeout' => 5, 'redirection' => 0, 'headers' => array( 'Accept' => 'application/json' ) )
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $this->error( 'jwks_unavailable', 'The Cognito signing keys are temporarily unavailable.', 503 );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > 262144 ) {
			return $this->error( 'jwks_invalid', 'The Cognito signing-key document is invalid.', 503 );
		}
		$value = json_decode( $body, true );
		if ( ! is_array( $value ) || ! is_array( $value['keys'] ?? null ) ) {
			return $this->error( 'jwks_invalid', 'The Cognito signing-key document is invalid.', 503 );
		}
		set_transient( $key, $value, 6 * HOUR_IN_SECONDS );
		return $value;
	}

	private function jwk_to_pem( array $jwk ): string|\WP_Error {
		if ( 'RSA' !== ( $jwk['kty'] ?? '' ) || empty( $jwk['n'] ) || empty( $jwk['e'] ) ) {
			return $this->error( 'jwks_key_invalid', 'The Cognito signing key is not a supported RSA key.', 503 );
		}
		$modulus = $this->decode_segment( (string) $jwk['n'] );
		$exponent = $this->decode_segment( (string) $jwk['e'] );
		if ( false === $modulus || false === $exponent ) {
			return $this->error( 'jwks_key_invalid', 'The Cognito RSA signing key could not be decoded.', 503 );
		}
		$rsa = $this->sequence( $this->integer( $modulus ) . $this->integer( $exponent ) );
		$algorithm = $this->sequence( "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00" );
		$der = $this->sequence( $algorithm . "\x03" . $this->length( strlen( $rsa ) + 1 ) . "\x00" . $rsa );
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PEM requires standard base64.
	}

	private function integer( string $value ): string {
		$value = ltrim( $value, "\x00" );
		if ( '' === $value ) { $value = "\x00"; }
		if ( ord( $value[0] ) > 0x7f ) { $value = "\x00" . $value; }
		return "\x02" . $this->length( strlen( $value ) ) . $value;
	}

	private function sequence( string $value ): string { return "\x30" . $this->length( strlen( $value ) ) . $value; }
	private function length( int $length ): string {
		if ( $length < 128 ) { return chr( $length ); }
		$encoded = '';
		while ( $length > 0 ) { $encoded = chr( $length & 0xff ) . $encoded; $length >>= 8; }
		return chr( 0x80 | strlen( $encoded ) ) . $encoded;
	}

	private function decode_json( string $value ): ?array {
		$decoded = $this->decode_segment( $value );
		if ( false === $decoded || strlen( $decoded ) > 65536 ) { return null; }
		$result = json_decode( $decoded, true );
		return is_array( $result ) ? $result : null;
	}

	private function audience_matches( mixed $claim, string $expected ): bool {
		$audiences = is_array( $claim ) ? $claim : array( $claim );
		foreach ( $audiences as $audience ) {
			if ( is_string( $audience ) && hash_equals( $expected, $audience ) ) { return true; }
		}
		return false;
	}

	private function decode_segment( string $value ): string|false {
		if ( ! preg_match( '/^[A-Za-z0-9_-]*$/', $value ) ) { return false; }
		$padding = strlen( $value ) % 4;
		if ( 0 !== $padding ) { $value .= str_repeat( '=', 4 - $padding ); }
		return base64_decode( strtr( $value, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT requires base64url decoding.
	}

	private function cache_key( string $issuer ): string { return 'scc_jwks_' . substr( hash( 'sha256', $issuer ), 0, 40 ); }
	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( 'smartcloud_composer_' . $code, $message, array( 'status' => $status ) );
	}
}
