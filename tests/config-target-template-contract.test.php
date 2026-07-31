<?php

use SmartCloud\AgentComposer\Application\Configuration\ConfigSetValidator;

require_once dirname( __DIR__ ) . '/src/Application/Configuration/ConfigSetValidator.php';

$validator = ( new ReflectionClass( ConfigSetValidator::class ) )->newInstanceWithoutConstructor();
$method    = new ReflectionMethod( ConfigSetValidator::class, 'validate_target_template' );
$method->setAccessible( true );

$validate = static function ( mixed $template ) use ( $validator, $method ): array {
	$errors = array();
	$method->invokeArgs(
		$validator,
		array(
			array( 'target_template' => $template ),
			'blueprint:test',
			&$errors,
		)
	);
	return $errors;
};

assert( array() === $validate( array( 'label' => 'Page', 'slug' => 'page-no-title' ) ) );
assert( array() === $validate( array( 'label' => 'Single', 'file' => 'templates/single-orvosok.html' ) ) );
assert( 'blueprint-target-template-exclusive' === ( $validate( array( 'slug' => 'page-no-title', 'file' => 'templates/page-no-title.html' ) )[0]['code'] ?? '' ) );
assert( 'blueprint-target-template-exclusive' === ( $validate( array( 'label' => 'Missing target' ) )[0]['code'] ?? '' ) );
assert( 'blueprint-target-template-slug-invalid' === ( $validate( array( 'slug' => 'Templates/Page' ) )[0]['code'] ?? '' ) );
assert( 'blueprint-target-template-file-invalid' === ( $validate( array( 'file' => '../single.php' ) )[0]['code'] ?? '' ) );

echo "Config target-template contract passed.\n";
