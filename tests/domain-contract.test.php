<?php

declare(strict_types=1);

use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;
use SmartCloud\AgentComposer\Domain\Configuration\EntityType;

require_once dirname( __DIR__ ) . '/src/Domain/Configuration/CanonicalJson.php';
require_once dirname( __DIR__ ) . '/src/Domain/Configuration/EntityType.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$first  = array( 'z' => 1, 'nested' => array( 'b' => 2, 'a' => 1 ), 'list' => array( 3, 2, 1 ) );
$second = array( 'list' => array( 3, 2, 1 ), 'nested' => array( 'a' => 1, 'b' => 2 ), 'z' => 1 );
$assert( CanonicalJson::encode( $first ) === CanonicalJson::encode( $second ), 'Canonical JSON must ignore associative key insertion order.' );
$assert( CanonicalJson::checksum( $first ) === CanonicalJson::checksum( $second ), 'Checksums must be deterministic.' );
$assert( 7 === count( EntityType::all() ), 'Composer must expose exactly seven configuration entity types.' );
$assert( in_array( 'blueprint', EntityType::all(), true ), 'Blueprint entity type is required.' );

try {
	EntityType::assert( 'invalid' );
	$failures[] = 'Unsupported entity types must be rejected.';
} catch ( InvalidArgumentException ) {
	// Expected.
}

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "Composer domain contracts passed.\n";
