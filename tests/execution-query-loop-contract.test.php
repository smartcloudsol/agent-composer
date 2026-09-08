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
	query_assert_true(in_array('show_author', $properties, true), 'The constrained query schema must expose the bounded author-name option.');
	query_assert_same(array('include', 'only', 'exclude'), $schema['properties']['sticky_mode']['enum'] ?? null, 'The constrained query schema must expose only supported sticky-post modes.');
	foreach (array('meta_query', 'post_status', 'author', 'password', 'include', 'exclude', 'search', 'raw_query') as $forbidden) {
		query_assert_true(! in_array($forbidden, $properties, true), 'The constrained query schema must not expose ' . $forbidden . '.');
	}
	query_assert_same(array('page_type', 'post_type'), $schema['required'] ?? null, 'The Query Loop ability must bind every request to a blueprint and post type.');

	$materializer = (new \ReflectionClass(Query_Loop_Materializer::class))->newInstanceWithoutConstructor();
	$template_method = new \ReflectionMethod(Query_Loop_Materializer::class, 'requested_template_blocks');
	$template_method->setAccessible(true);
	$template_blocks = $template_method->invoke(
		$materializer,
		array('show_featured_image' => true, 'show_excerpt' => true, 'show_date' => false, 'show_author' => true),
		array('allowed_template_blocks' => array('core/post-title', 'core/post-featured-image', 'core/post-excerpt', 'core/post-author-name'))
	);
	query_assert_same(
		array('core/post-title', 'core/post-featured-image', 'core/post-excerpt', 'core/post-author-name'),
		$template_blocks,
		'The materializer must derive template blocks from fixed boolean options.'
	);

	$post_template_method = new \ReflectionMethod(Query_Loop_Materializer::class, 'post_template_block');
	$post_template_method->setAccessible(true);
	$post_template = $post_template_method->invoke($materializer, $template_blocks, 3);
	query_assert_same('core/post-template', $post_template['blockName'] ?? null, 'The listing body must use core/post-template.');
	query_assert_same(3, $post_template['attrs']['layout']['columnCount'] ?? null, 'The bounded column count must reach the Grid layout.');
	query_assert_same(4, count(array_filter($post_template['innerContent'] ?? array(), static fn(mixed $part): bool => null === $part)), 'The Post Template must preserve one canonical placeholder per child.');
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

	$policy_method = new \ReflectionMethod(Query_Loop_Materializer::class, 'effective_policy');
	$policy_method->setAccessible(true);
	$effective = $policy_method->invoke(
		$materializer,
		array(
			'enabled' => true,
			'allowed_post_types' => array('post', 'page'),
			'allowed_taxonomies' => array('category', 'post_tag'),
			'allowed_orderby' => array('date', 'title'),
			'allowed_template_blocks' => array('core/post-title', 'core/post-author-name', 'core/post-excerpt'),
			'allowed_sticky_modes' => array('include', 'only', 'exclude'),
			'max_per_page' => 12,
			'max_offset' => 100,
		),
		array(
			'enabled' => true,
			'allowed_post_types' => array('post'),
			'allowed_taxonomies' => array('category'),
			'allowed_orderby' => array('date'),
			'allowed_template_blocks' => array('core/post-title', 'core/post-author-name'),
			'allowed_sticky_modes' => array('only', 'exclude'),
			'max_per_page' => 10,
			'max_offset' => 0,
		)
	);
	query_assert_same(true, $effective['enabled'], 'Both policy layers must opt in to Query Loop materialization.');
	query_assert_same(array('post'), $effective['allowed_post_types'], 'The Blueprint must narrow the global post-type ceiling.');
	query_assert_same(array('core/post-title', 'core/post-author-name'), $effective['allowed_template_blocks'], 'The Blueprint must narrow generated template blocks.');
	query_assert_same(array('only', 'exclude'), $effective['allowed_sticky_modes'], 'The Blueprint must narrow sticky-post modes.');
	query_assert_same(10, $effective['max_per_page'], 'The lower per-page ceiling must win.');
	query_assert_same(0, $effective['max_offset'], 'The lower offset ceiling must win.');

	$sticky_method = new \ReflectionMethod(Query_Loop_Materializer::class, 'requested_sticky_mode');
	$sticky_method->setAccessible(true);
	query_assert_same('only', $sticky_method->invoke($materializer, 'only', $effective), 'The featured blog slot must accept the Blueprint-approved sticky-only mode.');
	query_assert_same('exclude', $sticky_method->invoke($materializer, 'exclude', $effective), 'The latest-post blog slot must accept the Blueprint-approved sticky-exclude mode.');
	foreach (array('include', 'ONLY', 'only!', array('only')) as $invalid_sticky_mode) {
		try {
			$sticky_method->invoke($materializer, $invalid_sticky_mode, $effective);
			throw new RuntimeException('An unapproved or malformed sticky-post mode reached the Query Loop runtime.');
		} catch (\ReflectionException $error) {
			throw $error;
		} catch (Execution_Exception $error) {
			query_assert_same('query_sticky_mode_not_allowed', $error->get_execution_code(), 'Rejected sticky-post modes must use the governed error code.');
		}
	}

	$query_method = new \ReflectionMethod(Query_Loop_Materializer::class, 'query_attributes');
	$query_method->setAccessible(true);
	$featured_query = $query_method->invoke($materializer, 'post', 3, 0, 'DESC', 'date', 'only');
	query_assert_same('only', $featured_query['sticky'] ?? null, 'The featured blog Query Loop must materialize sticky=only.');
	query_assert_same(3, $featured_query['perPage'] ?? null, 'The featured blog Query Loop must materialize exactly three posts.');
	query_assert_same(false, $featured_query['inherit'] ?? null, 'The featured blog Query Loop must not inherit the ambient query.');
	$latest_query = $query_method->invoke($materializer, 'post', 10, 0, 'DESC', 'date', 'exclude');
	query_assert_same('exclude', $latest_query['sticky'] ?? null, 'The latest-post blog Query Loop must exclude sticky posts.');
	query_assert_same(10, $latest_query['perPage'] ?? null, 'The latest-post blog Query Loop must materialize ten posts per page.');

	$blog_blueprint = json_decode((string) file_get_contents(dirname(__DIR__) . '/presets/wpsuite/blueprints/blog-index.json'), true, 512, JSON_THROW_ON_ERROR);
	$blog_query_policy = $blog_blueprint['block_extensions']['query_loop_materializer'] ?? array();
	query_assert_same(array('only', 'exclude'), $blog_query_policy['allowed_sticky_modes'] ?? null, 'The blog-index Blueprint must allow exactly the featured and latest sticky modes.');
	query_assert_true(
		str_contains(implode(' ', $blog_blueprint['content_contract'] ?? array()), 'per_page 3 and sticky_mode only')
		&& str_contains(implode(' ', $blog_blueprint['content_contract'] ?? array()), 'per_page 10 and sticky_mode exclude'),
		'The blog-index Blueprint must define the featured-only and latest-exclude materialization calls.'
	);

	$disabled = $policy_method->invoke($materializer, array('enabled' => true), array('enabled' => false));
	query_assert_same(false, $disabled['enabled'], 'A Blueprint without an explicit opt-in must keep Query Loop materialization disabled.');

	$source = file_get_contents(dirname(__DIR__) . '/src/Execution/Query_Loop_Materializer.php');
	query_assert_true(is_string($source), 'The Query Loop materializer source must be readable.');
	query_assert_true(! str_contains($source, 'new \\WP_Query'), 'The materializer must never execute or proxy a raw WP_Query.');
	query_assert_true(str_contains($source, "'inherit'  => false"), 'The materialized Query Loop must never inherit the ambient template query.');

	echo "query-loop-contract: ok\n";
}
