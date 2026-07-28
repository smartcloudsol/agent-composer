<?php

declare(strict_types=1);

namespace {
	class WP_Post {
		public int $ID = 42;
	}
}

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	function get_preview_post_link( \WP_Post $post ): string {
		return 'http://example.test/?page_id=' . $post->ID . '&preview=true';
	}

	function home_url( string $path = '' ): string {
		return 'https://example.test' . $path;
	}

	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}

	function set_url_scheme( string $url, ?string $scheme = null ): string {
		return preg_replace( '#^[a-z][a-z0-9+.-]*://#i', $scheme . '://', $url ) ?? $url;
	}

	require_once dirname(__DIR__) . '/src/Execution/Draft_Service.php';

	$service = (new \ReflectionClass(Draft_Service::class))->newInstanceWithoutConstructor();
	$method  = new \ReflectionMethod(Draft_Service::class, 'preview_url');
	$method->setAccessible(true);

	$actual = $method->invoke($service, new \WP_Post());
	if ('https://example.test/?page_id=42&preview=true' !== $actual) {
		throw new RuntimeException('The preview URL must use the canonical site scheme, including under WP-CLI.');
	}

	echo "preview-url-contract: ok\n";
}
