<?php

declare(strict_types=1);

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	function sanitize_key(string $value): string {
		return strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $value));
	}

	function absint(mixed $value): int {
		return abs((int) $value);
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
				'related_items' => array(
					'read' => true,
					'write' => true,
					'semantic_type' => 'relation',
					'cardinality' => 'many',
					'ordered' => true,
					'target_post_types' => array('wps_service'),
					'target_post_statuses' => array('publish'),
					'maximum_items' => 6,
				),
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
				'related_items' => array(
					'read' => true,
					'write' => true,
					'semantic_type' => 'relation',
					'cardinality' => 'many',
					'ordered' => true,
					'target_post_types' => array('wps_service'),
					'target_post_statuses' => array('publish'),
					'maximum_items' => 6,
					'storage' => array('provider' => 'native-meta', 'value_format' => 'post_id'),
				),
			),
		),
		$policy,
		'The field policy must retain only explicit safe keys and make write imply read.'
	);
	$normalizeBlocks = new \ReflectionMethod(Config_Repository::class, 'normalize_registered_block_contracts');
	$normalizeBlocks->setAccessible(true);
	$blockContracts = $normalizeBlocks->invoke($repository, array(
		'external/related-records' => array(
			'rendering' => 'server',
			'attributes' => array(
				'relationField' => array('type' => 'string', 'required' => true, 'allowed' => array('related_services')),
				'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 6),
			),
		),
		'core/query' => array('rendering' => 'saved'),
	));
	field_assert_same(
		array(
			'external/related-records' => array(
				'rendering' => 'server',
				'attributes' => array(
					'relationField' => array('type' => 'string', 'enum' => array('related_services'), 'required' => true),
					'limit' => array('type' => 'integer', 'minimum' => 1.0, 'maximum' => 6.0),
				),
			),
		),
		$blockContracts,
		'Registered third-party block contracts must remain bounded and reject core aliases.'
	);

	$abilities = (new \ReflectionClass(Abilities::class))->newInstanceWithoutConstructor();
	$schema = $abilities->content_field_update_schema();
	field_assert_same(
		array('post_id', 'page_type', 'expected_modified_gmt', 'expected_revision', 'fields', 'confirm_update'),
		$schema['required'] ?? null,
		'Field writes must require target binding, both concurrency tokens, a field map, and confirmation.'
	);
	field_assert_same(array(true), $schema['properties']['confirm_update']['enum'] ?? null, 'Confirmation must accept true only.');
	$searchSchema = $abilities->relation_target_search_schema();
	field_assert_same(array('page_type', 'relation_field'), $searchSchema['required'] ?? null, 'Generic relation lookup must bind every query to a Blueprint and declared field.');
	field_assert_same(50, $searchSchema['properties']['limit']['maximum'] ?? null, 'Relation target lookup must remain bounded.');

	$source = file_get_contents(dirname(__DIR__) . '/src/Execution/Content_Field_Materializer.php');
	field_assert_true(is_string($source), 'The content field materializer source must be readable.');
	field_assert_true(str_contains($source, "get_registered_meta_keys( 'post', \$post_type )"), 'Fields must come from the WordPress meta registry.');
	field_assert_true(str_contains($source, 'get_content_field_access'), 'Every exposed field must pass the Site Contract allowlist.');
	field_assert_true(str_contains($source, 'update_owned_meta_fields'), 'Writes must cross the owned-draft concurrency boundary.');
	field_assert_true(! preg_match('/gasztro|gk_|orvosok|asszisztensek/i', $source), 'The generic Composer field gate must not contain site-specific identifiers.');
	$config_source = file_get_contents(dirname(__DIR__) . '/src/Execution/Config_Repository.php');
	field_assert_true(is_string($config_source) && str_contains($config_source, "'/^[a-z0-9_-]{1,20}$/'"), 'Composer must accept WordPress post type keys containing hyphens.');
	field_assert_true(str_contains($config_source, 'registered_block_contracts'), 'Composer must support explicit registered third-party block contracts.');
	field_assert_true(str_contains($source, 'assert_relation_value'), 'Composer must validate first-class relation targets before writing.');
	field_assert_true(str_contains($source, 'search_relation_targets'), 'Composer must resolve relation targets generically from the active Site Contract.');
	field_assert_true(str_contains($source, "current_user_can( 'read_post', \$candidate->ID )"), 'Relation target lookup must enforce per-record read capabilities.');
	$catalog_source = file_get_contents(dirname(__DIR__) . '/src/Execution/Block_Catalog.php');
	field_assert_true(is_string($catalog_source) && str_contains($catalog_source, '$has_registered_contract'), 'A declared block contract must be an alternative to a provider-specific Ability profile.');

	echo "content-field-contract: ok\n";
}
