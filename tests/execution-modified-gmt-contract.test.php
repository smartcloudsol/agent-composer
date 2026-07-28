<?php

declare(strict_types=1);

namespace {
	class WP_Post {
		public int $ID = 42;
		public string $post_modified_gmt = '0000-00-00 00:00:00';
	}
}

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	$updated_post = null;

	function wp_update_post(array $post, bool $wp_error = false): int {
		global $updated_post;
		$updated_post = $post;
		return (int) $post['ID'];
	}

	function is_wp_error(mixed $value): bool {
		return false;
	}

	function clean_post_cache(int $post_id): void {
	}

	function get_post(int $post_id): \WP_Post {
		$post = new \WP_Post();
		$post->ID = $post_id;
		$post->post_modified_gmt = '2026-07-24 13:14:15';
		return $post;
	}

	require_once dirname(__DIR__) . '/src/Execution/Draft_Service.php';

	function assert_same(mixed $expected, mixed $actual, string $message): void {
		if ($expected !== $actual) {
			throw new RuntimeException(
				$message . sprintf(' Expected %s, got %s.', var_export($expected, true), var_export($actual, true))
			);
		}
	}

	$service = (new \ReflectionClass(Draft_Service::class))->newInstanceWithoutConstructor();

	$token_method = new \ReflectionMethod(Draft_Service::class, 'modified_gmt_token');
	$token_method->setAccessible(true);
	assert_same(
		'1970-01-01T00:00:00Z',
		$token_method->invoke($service, '0000-00-00 00:00:00'),
		'A WordPress zero date must become a valid RFC 3339 concurrency token.'
	);
	assert_same(
		'1970-01-01T00:00:00Z',
		$token_method->invoke($service, ''),
		'An empty stored modification date must become the zero-date concurrency token.'
	);

	$expected_method = new \ReflectionMethod(Draft_Service::class, 'normalize_expected_modified_gmt');
	$expected_method->setAccessible(true);
	assert_same('', $expected_method->invoke($service, ''), 'A missing client token must remain invalid.');
	assert_same(
		'1970-01-01T00:00:00Z',
		$expected_method->invoke($service, '1970-01-01T00:00:00Z'),
		'The zero-date token returned by the Composer must round-trip.'
	);

	$post = new \WP_Post();
	$initialize_method = new \ReflectionMethod(Draft_Service::class, 'initialize_created_modified_gmt');
	$initialize_method->setAccessible(true);
	$initialized = $initialize_method->invoke($service, $post);
	assert_same(array('ID' => 42), $updated_post, 'A new zero-date draft must be touched once.');
	assert_same('2026-07-24 13:14:15', $initialized->post_modified_gmt, 'The touched draft must be read back.');

	echo "modified-gmt-contract: ok\n";
}
