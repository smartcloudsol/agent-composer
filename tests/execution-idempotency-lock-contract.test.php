<?php

declare(strict_types=1);

$test_options = array();
$test_uuid    = 0;

function get_current_blog_id(): int {
	return 7;
}

function wp_generate_uuid4(): string {
	global $test_uuid;
	++$test_uuid;
	return sprintf( '00000000-0000-4000-8000-%012d', $test_uuid );
}

function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}

function add_option( string $name, mixed $value, string $deprecated = '', bool $autoload = true ): bool {
	global $test_options;
	if ( array_key_exists( $name, $test_options ) ) {
		return false;
	}
	$test_options[ $name ] = $value;
	return true;
}

function get_option( string $name, mixed $default = false ): mixed {
	global $test_options;
	return $test_options[ $name ] ?? $default;
}

function wp_cache_delete( string $name, string $group = '' ): bool {
	return true;
}

final class FakeSQLiteWpdb {
	public string $options = 'wp_options';

	public function db_server_info(): string {
		return 'SQLite 3.46.1';
	}

	public function prepare( string $query, mixed ...$args ): array {
		return array( $query, $args );
	}

	public function query( array $prepared ): int|false {
		global $test_options;
		list( $query, $args ) = $prepared;
		if ( str_starts_with( $query, 'DELETE' ) ) {
			list( $name, $value ) = $args;
			if ( ( $test_options[ $name ] ?? null ) === $value ) {
				unset( $test_options[ $name ] );
				return 1;
			}
			return 0;
		}
		if ( str_starts_with( $query, 'UPDATE' ) ) {
			list( $value, $name, $expected ) = $args;
			if ( ( $test_options[ $name ] ?? null ) === $expected ) {
				$test_options[ $name ] = $value;
				return 1;
			}
			return 0;
		}
		return false;
	}
}

final class FakeMysqlWpdb {
	public string $options = 'wp_options';
	public array $queries  = array();

	public function db_server_info(): string {
		return '8.4.0';
	}

	public function prepare( string $query, mixed ...$args ): array {
		return array( $query, $args );
	}

	public function get_var( array $prepared ): string {
		$this->queries[] = $prepared[0];
		return '1';
	}
}

require_once dirname( __DIR__ ) . '/src/Execution/Execution_Exception.php';
require_once dirname( __DIR__ ) . '/src/Execution/Draft_Service.php';

use SmartCloud\AgentComposer\Execution\Draft_Service;

$service = ( new ReflectionClass( Draft_Service::class ) )->newInstanceWithoutConstructor();
$acquire = new ReflectionMethod( Draft_Service::class, 'acquire_idempotency_lock' );
$release = new ReflectionMethod( Draft_Service::class, 'release_idempotency_lock' );

$wpdb = new FakeSQLiteWpdb();
$lock = $acquire->invoke( $service, 42, 'sqlite-contract-key' );
if ( 'option' !== $lock['driver'] || ! isset( $test_options[ $lock['name'] ] ) ) {
	throw new RuntimeException( 'SQLite must acquire an option-backed idempotency lease.' );
}
$release->invoke( $service, $lock );
if ( isset( $test_options[ $lock['name'] ] ) ) {
	throw new RuntimeException( 'SQLite must release only its owned idempotency lease.' );
}

$stale_name                  = '_wpsuite_agent_lock_' . hash( 'sha256', 'wpsuite-agent-' . substr( hash( 'sha256', '7:42:stale-contract-key' ), 0, 48 ) );
$test_options[ $stale_name ] = json_encode( array( 'owner' => 'expired', 'expires_at' => time() - 1 ) );
$stale_lock                  = $acquire->invoke( $service, 42, 'stale-contract-key' );
if ( 'option' !== $stale_lock['driver'] || $stale_lock['value'] !== $test_options[ $stale_name ] ) {
	throw new RuntimeException( 'SQLite must atomically replace an expired idempotency lease.' );
}
$release->invoke( $service, $stale_lock );

$wpdb       = new FakeMysqlWpdb();
$mysql_lock = $acquire->invoke( $service, 42, 'mysql-contract-key' );
$release->invoke( $service, $mysql_lock );
if ( 'mysql' !== $mysql_lock['driver'] || ! str_contains( implode( '\n', $wpdb->queries ), 'GET_LOCK' ) || ! str_contains( implode( '\n', $wpdb->queries ), 'RELEASE_LOCK' ) ) {
	throw new RuntimeException( 'MySQL must retain advisory-lock acquisition and release.' );
}

echo "idempotency-lock-contract: ok\n";
