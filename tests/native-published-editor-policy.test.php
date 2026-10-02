<?php

declare(strict_types=1);

define( 'LOGGED_IN_COOKIE', 'wordpress_logged_in_test' );

class WP_Post {
	public function __construct( public int $ID = 1352, public string $post_type = 'post' ) {}
}

class WP_Post_Type {
	public object $cap;
	public function __construct() { $this->cap = (object) array( 'edit_published_posts' => 'edit_published_posts' ); }
}

class WP_REST_Request {
	public function __construct( private array $headers = array() ) {}
	public function get_header( string $name ): string { return (string) ( $this->headers[ $name ] ?? '' ); }
}

$GLOBALS['native_editor_can_edit'] = true;
function get_current_user_id(): int { return 7; }
function wp_validate_auth_cookie( string $cookie, string $scheme ): int|false { return 'valid-cookie' === $cookie && 'logged_in' === $scheme ? 7 : false; }
function wp_verify_nonce( string $nonce, string $action ): int|false { return 'valid-nonce' === $nonce && 'wp_rest' === $action ? 1 : false; }
function current_user_can( string $capability, mixed ...$args ): bool { return $GLOBALS['native_editor_can_edit']; }
function get_post_type_object( string $post_type ): ?WP_Post_Type { return 'post' === $post_type ? new WP_Post_Type() : null; }

require_once dirname( __DIR__ ) . '/src/Security/ActorContext.php';
require_once dirname( __DIR__ ) . '/src/Security/ActorIdentity.php';
require_once dirname( __DIR__ ) . '/src/Execution/Structure_Contract_Save_Guard.php';

use SmartCloud\AgentComposer\Execution\Structure_Contract_Save_Guard;
use SmartCloud\AgentComposer\Security\ActorContext;
use SmartCloud\AgentComposer\Security\ActorIdentity;

$guard = ( new ReflectionClass( Structure_Contract_Save_Guard::class ) )->newInstanceWithoutConstructor();
$check = new ReflectionMethod( Structure_Contract_Save_Guard::class, 'allows_native_published_edit' );
$post = new WP_Post();
$request = new WP_REST_Request( array( 'x_wp_nonce' => 'valid-nonce' ) );
$allowed = array( 'native_published_edit_policy' => 'browser-editor', 'published_update_policy' => 'proposal-only' );
$_COOKIE[ LOGGED_IN_COOKIE ] = 'valid-cookie';
$expect = static function ( bool $expected, bool $actual, string $case ): void {
	if ( $expected !== $actual ) throw new RuntimeException( 'Native editor policy failed: ' . $case );
};

$expect( true, $check->invoke( $guard, $request, $post, $allowed ), 'valid browser editor' );
$expect( false, $check->invoke( $guard, $request, $post, array( 'native_published_edit_policy' => 'blocked' ) ), 'policy blocked' );
$expect( false, $check->invoke( $guard, new WP_REST_Request(), $post, $allowed ), 'nonce missing' );
$expect( false, $check->invoke( $guard, new WP_REST_Request( array( 'x_wp_nonce' => 'valid-nonce', 'authorization' => 'Bearer token' ) ), $post, $allowed ), 'authorization header present' );
$_COOKIE[ LOGGED_IN_COOKIE ] = 'invalid-cookie';
$expect( false, $check->invoke( $guard, $request, $post, $allowed ), 'cookie invalid' );
$_COOKIE[ LOGGED_IN_COOKIE ] = 'valid-cookie';
$GLOBALS['native_editor_can_edit'] = false;
$expect( false, $check->invoke( $guard, $request, $post, $allowed ), 'capability missing' );
$GLOBALS['native_editor_can_edit'] = true;
ActorIdentity::set( new ActorContext( 'agent:1', '', '', '', array(), '', array(), 'agent', '', 'mcp' ) );
$expect( false, $check->invoke( $guard, $request, $post, $allowed ), 'MCP actor present' );
ActorIdentity::set( null );

echo "native-published-editor-policy: ok\n";
