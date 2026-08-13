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
	require_once dirname(__DIR__) . '/src/Execution/Config_Repository.php';

	function taxonomy_assert_same(mixed $expected, mixed $actual, string $message): void {
		if ($expected !== $actual) {
			throw new RuntimeException($message . sprintf(' Expected %s, got %s.', var_export($expected, true), var_export($actual, true)));
		}
	}

	function taxonomy_assert_true(bool $condition, string $message): void {
		if (! $condition) {
			throw new RuntimeException($message);
		}
	}

	$repository = (new \ReflectionClass(Config_Repository::class))->newInstanceWithoutConstructor();
	$normalize = new \ReflectionMethod(Config_Repository::class, 'content_taxonomy_access_policy');
	$normalize->setAccessible(true);
	$policy = $normalize->invoke(
		$repository,
		array(
			'post' => array(
				'category' => array(
					'search' => false,
					'assign' => false,
					'create' => true,
					'maximum_items' => 500,
					'assignment_mode' => 'append',
					'creation_parent_policy' => 'allowlist',
					'creation_parent_slugs' => array('News', 'product-updates', ''),
				),
				'post_tag' => array('search' => true, 'maximum_items' => 0, 'assignment_mode' => 'invalid'),
				'empty' => array('search' => false),
			),
			'page' => array('category' => array('search' => true)),
		),
		array('post')
	);

	taxonomy_assert_same(
		array(
			'post' => array(
				'category' => array(
					'search' => true,
					'assign' => true,
					'create' => true,
					'maximum_items' => 100,
					'assignment_mode' => 'append',
					'creation_parent_policy' => 'allowlist',
					'creation_parent_slugs' => array('news', 'product-updates'),
				),
				'post_tag' => array(
					'search' => true,
					'assign' => false,
					'create' => false,
					'maximum_items' => 1,
					'assignment_mode' => 'replace',
					'creation_parent_policy' => 'root-only',
					'creation_parent_slugs' => array(),
				),
			),
		),
		$policy,
		'Taxonomy policy normalization must enforce create => assign => search and all bounded defaults.'
	);

	$validator = file_get_contents(dirname(__DIR__) . '/src/Application/Configuration/ConfigSetValidator.php');
	$discovery = file_get_contents(dirname(__DIR__) . '/src/Application/Configuration/SiteDiscoveryService.php');
	taxonomy_assert_true(is_string($validator) && str_contains($validator, 'validate_content_taxonomy_access'), 'Whole-set validation must inspect taxonomy policy.');
	taxonomy_assert_true(str_contains((string) $validator, 'is_object_in_taxonomy'), 'Validation must bind a taxonomy to its post type.');
	taxonomy_assert_true(is_string($discovery) && str_contains($discovery, 'registered_taxonomies'), 'Discovery must expose registered taxonomies per post type.');
	taxonomy_assert_true(str_contains((string) $discovery, 'current_user_can_assign') && str_contains((string) $discovery, 'current_user_can_create'), 'Discovery must expose distinct assignment and creation capabilities.');

	echo "taxonomy-policy-contract: ok\n";
}
