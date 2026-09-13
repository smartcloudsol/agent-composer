<?php

declare(strict_types=1);

use SmartCloud\AgentComposer\Application\Configuration\ConfigSetValidator;
use SmartCloud\AgentComposer\Execution\Abilities;
use SmartCloud\AgentComposer\Execution\Config_Repository;

$root = dirname( __DIR__ );
require_once $root . '/src/Execution/Config_Repository.php';
require_once $root . '/src/Execution/Abilities.php';
require_once $root . '/src/Application/Configuration/ConfigSetValidator.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$repository = new Config_Repository();
$policy_property = new ReflectionProperty( Config_Repository::class, 'design_policy' );
$policy_property->setAccessible( true );

$abilities = ( new ReflectionClass( Abilities::class ) )->newInstanceWithoutConstructor();
$config_property = new ReflectionProperty( Abilities::class, 'config' );
$config_property->setAccessible( true );
$config_property->setValue( $abilities, $repository );

$policy_property->setValue( $repository, array( 'rendered_preview_policy' => 'required' ) );
$required_schema = $abilities->content_proposal_submit_schema();
$assert( $abilities->is_rendered_preview_required(), 'Required must remain the fail-closed policy.' );
$assert( in_array( 'rendered_preview_token', $required_schema['required'], true ), 'Required mode must require the rendered preview token in the public schema.' );

$policy_property->setValue( $repository, array( 'rendered_preview_policy' => 'optional' ) );
$optional_schema = $abilities->content_proposal_submit_schema();
$assert( ! $abilities->is_rendered_preview_required(), 'Optional mode must be discoverable by execution abilities.' );
$assert( ! in_array( 'rendered_preview_token', $optional_schema['required'], true ), 'Optional mode must allow proposal submission without the rendered preview token.' );
$assert( isset( $optional_schema['properties']['rendered_preview_token'] ), 'Optional mode must still accept and validate a supplied rendered preview token.' );

$validator = ( new ReflectionClass( ConfigSetValidator::class ) )->newInstanceWithoutConstructor();
$validate_policy = new ReflectionMethod( ConfigSetValidator::class, 'validate_rendered_preview_policy' );
$validate_policy->setAccessible( true );
$validate = static function ( array $design_policy ) use ( $validator, $validate_policy ): array {
	$errors = array();
	$validate_policy->invokeArgs( $validator, array( array( 'design_policy' => $design_policy ), &$errors ) );
	return $errors;
};
$assert( array() === $validate( array() ), 'Omitted preview policy must retain the backward-compatible default.' );
$assert( array() === $validate( array( 'rendered_preview_policy' => 'required' ) ), 'Required preview policy must validate.' );
$assert( array() === $validate( array( 'rendered_preview_policy' => 'optional' ) ), 'Optional preview policy must validate.' );
$assert( 'rendered-preview-policy-invalid' === ( $validate( array( 'rendered_preview_policy' => 'sometimes' ) )[0]['code'] ?? '' ), 'Unknown preview policies must fail Config Set validation.' );

$proposal_service = (string) file_get_contents( $root . '/src/Execution/Content_Proposal_Service.php' );
$assert( str_contains( $proposal_service, "'required' === \$rendered_preview_policy || '' !== \$rendered_preview_token" ), 'Submission must require a token only in required mode, while validating every supplied token.' );

$preset = json_decode( (string) file_get_contents( $root . '/presets/wpsuite/site-contract.json' ), true, 512, JSON_THROW_ON_ERROR );
$assert( 'optional' === ( $preset['design_policy']['rendered_preview_policy'] ?? '' ), 'The WP Suite development preset must make rendered preview optional.' );

echo "execution-rendered-preview-policy-contract: ok\n";
