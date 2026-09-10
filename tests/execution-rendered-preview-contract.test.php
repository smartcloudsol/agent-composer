<?php

declare(strict_types=1);

namespace {
	$registered_abilities = array();
	$preview_transients = array();
	$preview_fixture_root = sys_get_temp_dir() . '/smartcloud-preview-' . getmypid();
	@mkdir($preview_fixture_root . '/assets', 0777, true);
	define('WP_CONTENT_DIR', $preview_fixture_root);
	define('ABSPATH', $preview_fixture_root . '/');
	file_put_contents($preview_fixture_root . '/assets/pixel.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
	file_put_contents($preview_fixture_root . '/assets/preview.woff', 'wOFF' . str_repeat("\0", 28));
	file_put_contents($preview_fixture_root . '/assets/preview.woff2', 'wOF2' . str_repeat("\0", 28));
	file_put_contents($preview_fixture_root . '/assets/nested.css', '@import "preview.css";.nested{background-image:url("pixel.png")}');
	file_put_contents($preview_fixture_root . '/assets/preview.css', '@import "nested.css";.imported{font-family:Preview;background:url("pixel.png")}');

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

	function content_url(string $path = ''): string {
		return 'https://example.test/wp-content/' . ltrim($path, '/');
	}

	function get_current_user_id(): int {
		return 23;
	}

	function get_post_meta(int $post_id, string $key, bool $single = false): mixed {
		unset($post_id, $key, $single);
		return '';
	}

	function wp_salt(string $scheme = 'auth'): string {
		return 'preview-test-salt-' . $scheme;
	}

	function set_transient(string $key, mixed $value, int $expiration): bool {
		$GLOBALS['preview_transients'][$key] = array('value' => $value, 'expiration' => $expiration);
		return true;
	}

	function get_transient(string $key): mixed {
		return $GLOBALS['preview_transients'][$key]['value'] ?? false;
	}

	function wp_get_global_stylesheet(): string {
		return '@import url("https://blocked.example/theme.css");'
			. '@import url("https://example.test/wp-content/assets/preview.css") screen;'
			. '@font-face{font-family:Preview;src:url("https://example.test/wp-content/assets/preview.woff2") format("woff2"),url("https://example.test/wp-content/assets/preview.woff") format("woff")}'
			. '.card{color:green;background-image:url("https://example.test/wp-content/assets/pixel.png")}'
			. '.escaped{background:u\\72l(https://blocked.example/escaped.png)}'
			. '@\\69mport "https://blocked.example/escaped.css";'
			. '.set{background-image:image-set("https://blocked.example/image.png" 1x)}'
			. '.copy{font-weight:700}';
	}

	final class WP_Post {
		public int $ID = 73;
		public string $post_type = 'page';
		public string $post_name = 'gatey';
		public string $post_content = '<!-- wp:group {"layout":{"type":"constrained"}} --><!-- wp:paragraph --><p>Hello preview.</p><!-- /wp:paragraph --><!-- /wp:group --><script>alert(1)</script>';
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

	function sanitize_title(string $value): string {
		return trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $value)), '-');
	}

	function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed {
		unset($hook, $arguments);
		return $value;
	}

	function set_transient(string $key, mixed $value, int $expiration): bool {
		global $preview_transients;
		$preview_transients[$key] = array('value' => $value, 'expiration' => $expiration);
		return true;
	}

	function get_transient(string $key): mixed {
		global $preview_transients;
		return $preview_transients[$key]['value'] ?? false;
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
	require_once dirname(__DIR__) . '/src/Execution/Execution_Exception.php';
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
	$assert('3' === ($document['contract_version'] ?? ''), 'The attested rendered-HTML preview must use contract version 3.');
	$assert('rendered-html' === ($document['source_format'] ?? ''), 'The preview must identify its payload as rendered HTML.');
	$assert(!str_contains((string) ($document['html'] ?? ''), '<!-- wp:'), 'Gutenberg serialization comments must not reach the rendered HTML preview.');
	$assert(!str_contains((string) ($document['html'] ?? ''), '<script'), 'Active script markup must not reach the preview document.');
	$assert(str_contains((string) ($document['html'] ?? ''), 'Hello preview.'), 'Rendered block content must reach the preview document.');
	$assert(!str_contains((string) ($document['html'] ?? ''), '<header><h1>'), 'The preview body must not repeat the title already shown by the preview app chrome.');
	$assert(str_contains((string) ($document['html'] ?? ''), 'page-gatey'), 'The rendered document must carry frontend-compatible body context classes.');
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
	$assert(!str_contains(strtolower($css), '@import'), 'Preview CSS must flatten or omit @import rules.');
	$assert(!str_contains($css, 'u\\72l') && !str_contains($css, '@\\69mport'), 'Escaped CSS fetch identifiers must be neutralized before browser parsing.');
	$assert(!str_contains(strtolower($css), 'image-set('), 'String-based image-set fetches must be neutralized.');
	$assert(str_contains($css, '@media screen{'), 'A bounded local import with a media query must be flattened into a media wrapper.');
	$assert(substr_count($css, 'smartcloud-preview-asset://pa_') >= 3, 'Local CSS image and font URLs must become opaque private asset references.');
	$assert(str_contains($css, '.copy{font-weight:700}'), 'Safe CSS declarations must be retained.');
	$kinds = array_count_values(array_map(static fn(array $asset): string => (string) ($asset['kind'] ?? ''), (array) ($document['assets'] ?? array())));
	$assert(1 === ($kinds['image'] ?? 0), 'Repeated local CSS image references must share one private image asset.');
	$assert(2 === ($kinds['font'] ?? 0), 'Local WOFF and WOFF2 references must be exposed as private font assets.');
	$assert(1 === ($kinds['stylesheet'] ?? 0), 'Flattened imports must remain part of their parent stylesheet asset.');
	$store_snapshot = new \ReflectionMethod(Rendered_Preview_Service::class, 'store_asset_snapshot');
	$load_snapshot = new \ReflectionMethod(Rendered_Preview_Service::class, 'load_asset_snapshot');
	$store_snapshot->invoke($service, 73, '123e4567-e89b-12d3-a456-426614174000', $asset_sources);
	$cached_sources = $load_snapshot->invoke($service, 73, '123e4567-e89b-12d3-a456-426614174000');
	$assert($asset_sources === $cached_sources, 'Preview asset sources must survive request boundaries in a user- and revision-bound transient snapshot.');
	$assert(null === $load_snapshot->invoke($service, 73, '00000000-0000-4000-8000-000000000000'), 'A different revision must not read a cached preview asset snapshot.');
	$assert(1800 === ($GLOBALS['preview_transients'][array_key_first($GLOBALS['preview_transients'])]['expiration'] ?? 0), 'Preview asset snapshots must expire after the bounded review window.');
	$assert_expected_version = new \ReflectionMethod(Rendered_Preview_Service::class, 'assert_expected_version');
	$assert_expected_version->invoke($service, array(
		'expected_modified_gmt' => '1970-01-01T00:00:00.000Z',
		'expected_revision' => '123e4567-e89b-12d3-a456-426614174000',
	), array(
		'modified_gmt' => '1970-01-01T00:00:00Z',
		'revision' => '123e4567-e89b-12d3-a456-426614174000',
	));
	$token = (string) ($result['rendered_preview_token'] ?? '');
	$assert(preg_match('/^pv1\.[0-9]{10}\.[A-Za-z0-9_-]{43}$/', $token) === 1, 'The rendered preview must return a bounded revision attestation token.');
	Rendered_Preview_Service::assert_submission_token($token, 73, '2026-09-07T12:00:00Z', '123e4567-e89b-12d3-a456-426614174000', 23);
	try {
		Rendered_Preview_Service::assert_submission_token($token, 73, '2026-09-07T12:00:00Z', '00000000-0000-4000-8000-000000000000', 23);
		$assert(false, 'A rendered preview token must be bound to the exact draft revision.');
	} catch (Execution_Exception $error) {
		$assert('rendered_preview_required' === $error->get_execution_code(), 'A revision mismatch must require a fresh rendered preview.');
	}
	$asset_snapshot = $service->build_document(
		new \WP_Post(),
		array(
			'edit_url' => 'https://example.test/wp-admin/post.php?post=73&action=edit',
			'preview_url' => 'https://example.test/?p=73&preview=true',
			'modified_gmt' => '2026-09-07T12:00:00Z',
			'revision' => '123e4567-e89b-12d3-a456-426614174000',
			'validation' => array('valid' => true),
		),
		'ar-SA',
		false
	);
	$assert(!array_key_exists('rendered_preview_token', $asset_snapshot), 'A read-only asset snapshot must not mint a proposal-submission token.');

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
	$asset_schema = $asset_ability['input_schema'] ?? array();
	$assert(array('post_id', 'expected_revision', 'asset_id') === ($asset_schema['required'] ?? null), 'Private asset reads must rely on the exact revision instead of a mutable modification timestamp.');
	$assert(isset($asset_schema['properties']['expected_modified_gmt']), 'The legacy asset timestamp input must remain accepted for cached preview clients.');
	$resource_uris = array(
		'smartcloud-agent-composer/rendered-preview-app' => 'ui://smartcloud-agent-composer/rendered-preview/v5.html',
		'smartcloud-agent-composer/rendered-preview-app-v4' => 'ui://smartcloud-agent-composer/rendered-preview/v4.html',
		'smartcloud-agent-composer/rendered-preview-app-v3' => 'ui://smartcloud-agent-composer/rendered-preview/v3.html',
		'smartcloud-agent-composer/rendered-preview-app-v2' => 'ui://smartcloud-agent-composer/rendered-preview/v2.html',
		'smartcloud-agent-composer/rendered-preview-app-v1' => 'ui://smartcloud-agent-composer/rendered-preview/v1.html',
	);
	$latest_app_html = null;
	foreach ($resource_uris as $resource_name => $resource_uri) {
		$resource = $GLOBALS['registered_abilities'][$resource_name] ?? array();
		$assert(!array_key_exists('input_schema', $resource), 'An argument-free MCP resource must accept the adapter null-input execution path.');
		$resource_callback = $resource['execute_callback'] ?? null;
		$assert(is_callable($resource_callback), 'Every rendered preview resource alias must be registered.');
		$contents = $resource_callback();
		$assert('text/html;profile=mcp-app' === ($contents[0]['mimeType'] ?? ''), 'The rendered preview resource must retain the MCP Apps MIME type.');
		$assert($resource_uri === ($contents[0]['uri'] ?? ''), 'Every resource alias must answer on its advertised URI.');
		$app_html = (string) ($contents[0]['text'] ?? '');
		$latest_app_html ??= $app_html;
		$assert(hash_equals($latest_app_html, $app_html), 'Legacy preview URIs must serve the exact latest preview app HTML.');
	}
	$assert(str_contains($latest_app_html, "'ui/initialize'"), 'The rendered preview app must initialize the MCP Apps bridge.');
	$assert(str_contains($latest_app_html, "'ui/notifications/initialized'"), 'The rendered preview app must complete the MCP Apps handshake.');
	$assert(str_contains($latest_app_html, 'attachShadow'), 'The rendered preview app must isolate site CSS in a ShadowRoot.');
	$assert(str_contains($latest_app_html, 'smartcloud-agent-composer-get-rendered-preview-asset'), 'The rendered preview app must call the private asset helper.');
	$assert(str_contains($latest_app_html, '[payload,payload?.result,payload?.result?.result]'), 'The preview app must prefer a top-level MCP content array over an empty compatibility result field.');
	$assert(str_contains($latest_app_html, "asset.kind==='font'"), 'The rendered preview app must decode private font resources.');
	$assert(str_contains($latest_app_html, 'smartcloud-preview-asset://'), 'The rendered preview app must rewrite private CSS dependency placeholders to Blob URLs.');
	$assert(!str_contains($latest_app_html, 'expected_modified_gmt:doc.modified_gmt'), 'The preview app must not bind read-only asset delivery to a mutable timestamp.');
	$assert(str_contains($latest_app_html, "documentKey===lastDocumentKey"), 'Duplicate host delivery channels must not start duplicate asset batches for the same preview document.');
	$assert(str_contains($latest_app_html, 'Math.min(2,items.length)'), 'The preview app must keep private MCP asset request concurrency bounded.');
	$assert(str_contains($latest_app_html, "document.createElement('body')"), 'The isolated preview must recreate a body context for theme selectors.');
	$assert(str_contains($latest_app_html, "shell.classList.add('smartcloud-composer-preview-document')"), 'Every recreated body must receive the preview sizing class alongside its frontend context classes.');
	$assert(str_contains($latest_app_html, 'height:auto!important'), 'The recreated body must grow with long content instead of letting its background stop at the preview viewport height.');
	$assert(str_contains($latest_app_html, 'height:clamp(320px,65vh,640px)'), 'The preview app must keep long rendered pages inside a bounded scrolling viewport.');
	$assert(str_contains($latest_app_html, 'max-height:112px'), 'The preview warning list must not grow the conversation card without a bound.');
	$assert(str_contains($latest_app_html, "content.className='wp-block-post-content'"), 'The isolated preview must recreate the WordPress content wrapper.');
	$assert(str_contains($latest_app_html, 'function safeStyle'), 'Safe Gutenberg inline presentation styles must remain available in the rendered preview.');
	$assert(str_contains($latest_app_html, 'audio,video,source,track,picture'), 'The client sanitizer must remove passive media elements that could fetch external resources.');
	$assert(str_contains($latest_app_html, "version:'5.0.0'"), 'Every resource alias must serve the latest v5 preview app.');

	foreach (glob($preview_fixture_root . '/assets/*') ?: array() as $fixture) {
		@unlink($fixture);
	}
	@rmdir($preview_fixture_root . '/assets');
	@rmdir($preview_fixture_root);

	echo "rendered-preview-contract: ok\n";
}
