<?php

declare(strict_types=1);

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	function sanitize_key(string $value): string {
		return strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $value));
	}

	function wp_json_encode(mixed $value): string|false {
		return json_encode($value);
	}

	function is_object_in_taxonomy(string $post_type, string $taxonomy): bool {
		return 'wps_doctor' === $post_type && 'specialty' === $taxonomy;
	}

	function get_taxonomy(string $taxonomy): object|false {
		return 'specialty' === $taxonomy ? (object) array('public' => true, 'publicly_queryable' => true) : false;
	}

	function term_exists(int $term_id, string $taxonomy): int|false {
		return 'specialty' === $taxonomy && in_array($term_id, array(3, 7), true) ? $term_id : false;
	}

	require_once dirname(__DIR__) . '/src/Execution/Execution_Exception.php';
	require_once dirname(__DIR__) . '/src/Execution/Abilities.php';
	require_once dirname(__DIR__) . '/src/Execution/Query_Loop_Materializer.php';

	function query_assert_same(mixed $expected, mixed $actual, string $message): void {
		if ($expected !== $actual) {
			throw new RuntimeException($message . sprintf(' Expected %s, got %s.', var_export($expected, true), var_export($actual, true)));
		}
	}

	function query_assert_true(bool $condition, string $message): void {
		if (! $condition) {
			throw new RuntimeException($message);
		}
	}

	$abilities = (new \ReflectionClass(Abilities::class))->newInstanceWithoutConstructor();
	$schema = $abilities->query_loop_schema();
	$properties = array_keys($schema['properties'] ?? array());
	foreach (array('meta_query', 'post_status', 'author', 'password', 'include', 'exclude', 'search', 'raw_query') as $forbidden) {
		query_assert_true(! in_array($forbidden, $properties, true), 'The constrained query schema must not expose ' . $forbidden . '.');
	}
	query_assert_same(array('page_type', 'post_type'), $schema['required'] ?? null, 'The Query Loop ability must bind every request to a blueprint and post type.');

	$materializer = (new \ReflectionClass(Query_Loop_Materializer::class))->newInstanceWithoutConstructor();
	$template_method = new \ReflectionMethod(Query_Loop_Materializer::class, 'requested_template_blocks');
	$template_method->setAccessible(true);
	$template_blocks = $template_method->invoke(
		$materializer,
		array('show_featured_image' => true, 'show_excerpt' => true, 'show_date' => false),
		array('allowed_template_blocks' => array('core/post-title', 'core/post-featured-image', 'core/post-excerpt'))
	);
	query_assert_same(
		array('core/post-title', 'core/post-featured-image', 'core/post-excerpt'),
		$template_blocks,
		'The materializer must derive template blocks from fixed boolean options.'
	);

	$post_template_method = new \ReflectionMethod(Query_Loop_Materializer::class, 'post_template_block');
	$post_template_method->setAccessible(true);
	$post_template = $post_template_method->invoke($materializer, $template_blocks, 3);
	query_assert_same('core/post-template', $post_template['blockName'] ?? null, 'The listing body must use core/post-template.');
	query_assert_same(3, $post_template['attrs']['layout']['columnCount'] ?? null, 'The bounded column count must reach the Grid layout.');
	query_assert_same(3, count(array_filter($post_template['innerContent'] ?? array(), static fn(mixed $part): bool => null === $part)), 'The Post Template must preserve one canonical placeholder per child.');
	query_assert_same(true, $post_template['innerBlocks'][0]['attrs']['isLink'] ?? null, 'Post titles must link to the listed item.');
	query_assert_same(true, $post_template['innerBlocks'][1]['attrs']['isLink'] ?? null, 'Featured images must link to the listed item.');

	$taxonomy_method = new \ReflectionMethod(Query_Loop_Materializer::class, 'taxonomy_query');
	$taxonomy_method->setAccessible(true);
	$tax_query = $taxonomy_method->invoke(
		$materializer,
		array(array('taxonomy' => 'specialty', 'term_ids' => array(3, 7, 3))),
		'wps_doctor',
		array('allowed_taxonomies' => array('specialty'))
	);
	query_assert_same(array('specialty' => array(3, 7)), $tax_query, 'Taxonomy filters must contain existing unique integer term IDs only.');

	$source = file_get_contents(dirname(__DIR__) . '/src/Execution/Query_Loop_Materializer.php');
	query_assert_true(is_string($source), 'The Query Loop materializer source must be readable.');
	query_assert_true(! str_contains($source, 'new \\WP_Query'), 'The materializer must never execute or proxy a raw WP_Query.');
	query_assert_true(str_contains($source, "'inherit'  => false"), 'The materialized Query Loop must never inherit the ambient template query.');

	echo "query-loop-contract: ok\n";
}
