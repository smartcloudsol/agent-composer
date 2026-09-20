<?php

/**
 * Disposable RSA/JWKS fixture for WordPress integration tests.
 *
 * The private key never leaves the test process. Production code still parses
 * and verifies a normal RS256 Cognito-shaped access token against cached JWKS.
 */
final class SmartCloud_Composer_Cognito_Jwt_Fixture {
	private \OpenSSLAsymmetricKey $private_key;
	private string $kid;

	public function __construct() {
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		if ( ! $key instanceof \OpenSSLAsymmetricKey ) {
			throw new RuntimeException( 'Could not create the disposable Cognito test key.' );
		}
		$this->private_key = $key;
		$this->kid         = 'composer-e2e-' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 24 );
	}

	public function prime_jwks( string $issuer ): string {
		$details = openssl_pkey_get_details( $this->private_key );
		if ( ! is_array( $details ) || ! is_array( $details['rsa'] ?? null ) ) {
			throw new RuntimeException( 'Could not read the disposable Cognito public key.' );
		}
		$cache_key = 'scc_jwks_' . substr( hash( 'sha256', $issuer ), 0, 40 );
		set_transient(
			$cache_key,
			array(
				'keys' => array(
					array(
						'alg' => 'RS256',
						'e'   => $this->base64url( (string) $details['rsa']['e'] ),
						'kid' => $this->kid,
						'kty' => 'RSA',
						'n'   => $this->base64url( (string) $details['rsa']['n'] ),
						'use' => 'sig',
					),
				),
			),
			HOUR_IN_SECONDS
		);
		return $cache_key;
	}

	public function access_token( array $claims ): string {
		$header = array( 'alg' => 'RS256', 'kid' => $this->kid, 'typ' => 'JWT' );
		$now    = time();
		$claims = array_merge(
			array(
				'exp'       => $now + 600,
				'iat'       => $now,
				'sub'       => 'composer-e2e-principal',
				'token_use' => 'access',
			),
			$claims
		);
		$encoded = $this->base64url( (string) wp_json_encode( $header ) ) . '.'
			. $this->base64url( (string) wp_json_encode( $claims ) );
		$signature = '';
		if ( ! openssl_sign( $encoded, $signature, $this->private_key, OPENSSL_ALGO_SHA256 ) ) {
			throw new RuntimeException( 'Could not sign the disposable Cognito access token.' );
		}
		return $encoded . '.' . $this->base64url( $signature );
	}

	private function base64url( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT and JWK fixtures require base64url.
	}
}
