<?php

declare(strict_types=1);

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	function sanitize_key(string $value): string {
		return strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $value));
	}

	require_once dirname(__DIR__) . '/src/Execution/Execution_Exception.php';
	require_once dirname(__DIR__) . '/src/Execution/Abilities.php';
	require_once dirname(__DIR__) . '/src/Execution/Config_Repository.php';
	require_once dirname(__DIR__) . '/src/Execution/Content_Field_Materializer.php';

	function field_assert_true(bool $condition, string $message): void {
		if (! $condition) {
			throw new RuntimeException($message);
		}
	}

	function field_assert_same(mixed $expected, mixed $actual, string $message): void {
		if ($expected !== $actual) {
			throw new RuntimeException($message . sprintf(' Expected %s, got %s.', var_export($expected, true), var_export($actual, true)));
		}
	}

	$repository = (new \ReflectionClass(Config_Repository::class))->newInstanceWithoutConstructor();
	$normalize = new \ReflectionMethod(Config_Repository::class, 'content_field_access_policy');
	$normalize->setAccessible(true);
	$policy = $normalize->invoke(
		$repository,
		array(
			'wps_doctor' => array(
				'public_summary' => array('read' => true, 'write' => false),
				'booking-url' => array('read' => false, 'write' => true),
				'_private_key' => array('read' => true, 'write' => true),
				'bad key' => array('read' => true, 'write' => true),
			),
			'not_allowed' => array('field' => array('read' => true)),
		),
		array('wps_doctor')
	);
	field_assert_same(
		array(
			'wps_doctor' => array(
				'public_summary' => array('read' => true, 'write' => false),
				'booking-url' => array('read' => true, 'write' => true),
			),
		),
		$policy,
		'The field policy must retain only explicit safe keys and make write imply read.'
	);

	$abilities = (new \ReflectionClass(Abilities::class))->newInstanceWithoutConstructor();
	$schema = $abilities->content_field_update_schema();
	field_assert_same(
		array('post_id', 'page_type', 'expected_modified_gmt', 'expected_revision', 'fields', 'confirm_update'),
		$schema['required'] ?? null,
		'Field writes must require target binding, both concurrency tokens, a field map, and confirmation.'
	);
	field_assert_same(array(true), $schema['properties']['confirm_update']['enum'] ?? null, 'Confirmation must accept true only.');

	$source = file_get_contents(dirname(__DIR__) . '/src/Execution/Content_Field_Materializer.php');
	field_assert_true(is_string($source), 'The content field materializer source must be readable.');
	field_assert_true(str_contains($source, "get_registered_meta_keys( 'post', \$post_type )"), 'Fields must come from the WordPress meta registry.');
	field_assert_true(str_contains($source, 'get_content_field_access'), 'Every exposed field must pass the Site Contract allowlist.');
	field_assert_true(str_contains($source, 'update_owned_meta_fields'), 'Writes must cross the owned-draft concurrency boundary.');
	field_assert_true(! preg_match('/gasztro|gk_|orvosok|asszisztensek/i', $source), 'The generic Composer field gate must not contain site-specific identifiers.');
	$config_source = file_get_contents(dirname(__DIR__) . '/src/Execution/Config_Repository.php');
	field_assert_true(is_string($config_source) && str_contains($config_source, "'/^[a-z0-9_-]{1,20}$/'"), 'Composer must accept WordPress post type keys containing hyphens.');

	echo "content-field-contract: ok\n";
}
