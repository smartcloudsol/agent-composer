<?php

namespace SmartCloud\AgentComposer\Domain\Configuration;

use JsonException;

final class CanonicalJson {
	/** @throws JsonException */
	public static function encode( mixed $value ): string {
		return json_encode(
			self::sort( $value ),
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
		);
	}

	public static function checksum( mixed $value ): string {
		return 'sha256:' . hash( 'sha256', self::encode( $value ) );
	}

	private static function sort( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( array( self::class, 'sort' ), $value );
		}
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sort( $item );
		}
		return $value;
	}

	private function __construct() {}
}
