<?php

declare(strict_types=1);

namespace {
	$registered_abilities = array();

	function wp_has_ability(string $name): bool {
		unset($name);
		return false;
	}

	function wp_register_ability(string $name, array $arguments): void {
		global $registered_abilities;
		$registered_abilities[$name] = $arguments;
	}

	function wp_upload_dir(?string $time = null, bool $create_dir = true): array {
		return array('baseurl' => 'https://media.example.test/uploads', 'error' => false);
	}

	function site_url(string $path = ''): string {
		return 'https://admin.example.test' . $path;
	}

	function wp_get_global_stylesheet(): string {
		return '@import url("https://blocked.example/theme.css");.card{color:green;background-image:url("https://blocked.example/image.png")}.copy{font-weight:700}';
	}

	final class WP_Post {
		public int $ID = 73;
		public string $post_content = '<!-- wp:paragraph --><p>Hello preview.</p><!-- /wp:paragraph --><script>alert(1)</script>';
	}
}

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	function do_blocks(string $content): string {
		return str_replace(array('<!-- wp:paragraph -->', '<!-- /wp:paragraph -->'), '', $content);
	}

	function wp_kses_post(string $html): string {
		return preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
	}

	function sanitize_text_field(string $value): string {
		return trim($value);
	}

	function sanitize_key(string $value): string {
		return strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $value));
	}

	function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed {
		unset($hook, $arguments);
		return $value;
	}

	function get_the_title(\WP_Post $post): string {
		return 'Rendered & safe';
	}

	function wp_strip_all_tags(string $value): string {
		return strip_tags($value);
	}

	function esc_attr(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
	}

	function esc_html(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
	}

	function home_url(string $path = ''): string {
		return 'https://example.test' . $path;
	}

	function wp_parse_url(string $url, int $component = -1): mixed {
		return parse_url($url, $component);
	}

	require_once dirname(__DIR__) . '/src/Execution/Rendered_Preview_Service.php';
	require_once dirname(__DIR__) . '/src/Execution/Abilities.php';
	require_once dirname(__DIR__) . '/src/Integration/Mcp/ComposerMcpServer.php';

	$service = (new \ReflectionClass(Rendered_Preview_Service::class))->newInstanceWithoutConstructor();
	$result = $service->build_document(
		new \WP_Post(),
		array(
			'edit_url' => 'https://example.test/wp-admin/post.php?post=73&action=edit',
			'preview_url' => 'https://example.test/?p=73&preview=true',
			'modified_gmt' => '2026-09-07T12:00:00Z',
			'revision' => '123e4567-e89b-12d3-a456-426614174000',
			'validation' => array('valid' => true),
		),
		'ar-SA'
	);

	$assert = static function (bool $condition, string $message): void {
		if (!$condition) {
			throw new RuntimeException($message);
		}
	};

	$document = $result['document'] ?? array();
	$assert(73 === ($result['post_id'] ?? 0), 'The rendered preview must retain the authorized draft ID.');
	$assert('rtl' === ($document['direction'] ?? ''), 'RTL content languages must produce an RTL preview document.');
	$assert('content' === ($document['scope'] ?? ''), 'The first preview contract must remain content-scoped.');
	$assert('static' === ($document['fidelity'] ?? ''), 'The embedded preview must declare static fidelity.');
	$assert('2' === ($document['contract_version'] ?? ''), 'The asset-backed preview must use contract version 2.');
	$assert(!str_contains((string) ($document['html'] ?? ''), '<script'), 'Active script markup must not reach the preview document.');
	$assert(str_contains((string) ($document['html'] ?? ''), 'Hello preview.'), 'Rendered block content must reach the preview document.');
	$assert(hash_equals(hash('sha256', (string) $document['html']), (string) ($document['sha256'] ?? '')), 'The preview hash must cover the exact returned HTML.');
	$assert(strlen((string) $document['html']) === ($document['byte_length'] ?? -1), 'The preview byte length must cover the exact returned HTML.');
	$assert(in_array('https://media.example.test', Rendered_Preview_Service::allowed_asset_origins(), true), 'The configured uploads origin must be admitted to the MCP Apps CSP.');
	$assert(in_array('https://admin.example.test', Rendered_Preview_Service::allowed_asset_origins(), true), 'A distinct WordPress site origin must be admitted to the MCP Apps CSP.');
	$assert('active_markup_removed' === ($document['warnings'][1]['code'] ?? ''), 'Sanitizer changes must be reported to the client.');
	$stylesheets = array_values(array_filter((array) ($document['assets'] ?? array()), static fn(array $asset): bool => 'stylesheet' === ($asset['kind'] ?? '')));
	$assert(1 === count($stylesheets), 'The bounded global stylesheet must be represented by one opaque asset.');
	$assert(preg_match('/^pa_[A-Za-z0-9_-]{43}$/', (string) ($stylesheets[0]['asset_id'] ?? '')) === 1, 'Preview assets must use opaque HMAC identifiers.');
	$assert(!array_key_exists('url', $stylesheets[0]), 'The public asset manifest must not expose a URL.');
	$asset_sources = (new \ReflectionProperty(Rendered_Preview_Service::class, 'asset_sources'))->getValue($service);
	$css = (string) ($asset_sources[$stylesheets[0]['asset_id']]['content'] ?? '');
	$assert(!str_contains(strtolower($css), '@import'), 'Preview CSS must omit @import rules.');
	$assert(!str_contains(strtolower($css), 'url('), 'Preview CSS must omit url() references.');
	$assert(str_contains($css, '.copy{font-weight:700}'), 'Safe CSS declarations must be retained.');

	$abilities = (new \ReflectionClass(Abilities::class))->newInstanceWithoutConstructor();
	$register_resource = new \ReflectionMethod(Abilities::class, 'register_rendered_preview_resource');
	$register_resource->invoke($abilities);
	$register_asset = new \ReflectionMethod(Abilities::class, 'register_rendered_preview_asset_ability');
	$register_asset->invoke($abilities);
	$asset_ability = $GLOBALS['registered_abilities']['smartcloud-agent-composer/get-rendered-preview-asset'] ?? array();
	$assert(!array_key_exists('output_schema', $asset_ability), 'The MCP special-content asset helper must not declare an ordinary output schema.');
	$assert(array('app') === ($asset_ability['meta']['mcp']['_meta']['ui']['visibility'] ?? null), 'The preview asset helper must be app-only.');
	$assert('private' === ($asset_ability['meta']['mcp']['_meta']['openai/visibility'] ?? ''), 'The preview asset helper must be hidden from the model-facing tool surface.');
	$assert(true === ($asset_ability['meta']['mcp']['_meta']['openai/widgetAccessible'] ?? false), 'The preview app must be allowed to call its private helper.');
	$resource = $GLOBALS['registered_abilities']['smartcloud-agent-composer/rendered-preview-app'] ?? array();
	$assert(!array_key_exists('input_schema', $resource), 'An argument-free MCP resource must accept the adapter null-input execution path.');
	$resource_callback = $resource['execute_callback'] ?? null;
	$assert(is_callable($resource_callback), 'The rendered preview resource callback must be registered.');
	$contents = $resource_callback();
	$assert('text/html;profile=mcp-app' === ($contents[0]['mimeType'] ?? ''), 'The rendered preview resource must retain the MCP Apps MIME type.');
	$assert(str_contains((string) ($contents[0]['text'] ?? ''), "'ui/initialize'"), 'The rendered preview app must initialize the MCP Apps bridge.');
	$assert(str_contains((string) ($contents[0]['text'] ?? ''), "'ui/notifications/initialized'"), 'The rendered preview app must complete the MCP Apps handshake.');
	$assert(str_contains((string) ($contents[0]['text'] ?? ''), 'attachShadow'), 'The rendered preview app must isolate site CSS in a ShadowRoot.');
	$assert(str_contains((string) ($contents[0]['text'] ?? ''), 'smartcloud-agent-composer-get-rendered-preview-asset'), 'The rendered preview app must call the private asset helper.');

	echo "rendered-preview-contract: ok\n";
}
