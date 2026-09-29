<?php

declare(strict_types=1);

namespace {
	$registered_abilities = array();
	$preview_transients = array();
	$composer_preview_filters = array();
	$rendered_preview_language_seen = '';
	$preview_template_enabled = false;
	$preview_block_theme = true;
	$preview_template_post = null;
	$preview_post_meta = array();
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
	function absint(mixed $value): int { return abs((int) $value); }

	function get_post_meta(int $post_id, string $key, bool $single = false): mixed {
		unset($single);
		return $GLOBALS['preview_post_meta'][$post_id][$key] ?? '';
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
		public string $post_status = 'draft';
		public string $post_name = 'gatey';
		public string $post_content = '<!-- wp:group {"layout":{"type":"constrained"}} --><!-- wp:paragraph --><p>Hello preview.</p><!-- /wp:paragraph --><!-- /wp:group --><script>alert(1)</script>';
	}

	final class WP_Query {
		public int $post_count;
		public function __construct(array $arguments) {
			$this->post_count = $GLOBALS['preview_template_enabled'] && ($arguments['post_type'] ?? '') === $GLOBALS['preview_template_post']->post_type ? 1 : 0;
		}
		public function have_posts(): bool { return 1 === $this->post_count; }
		public function the_post(): void { $GLOBALS['post'] = $GLOBALS['preview_template_post']; }
	}
	function wp_is_block_theme(): bool { return $GLOBALS['preview_block_theme']; }
	function get_page_template(): string { return get_single_template(); }
	function get_single_template(): string {
		if ($GLOBALS['preview_block_theme']) {
			$GLOBALS['_wp_current_template_content'] = '<div class="doctor-template">PROFILE_TEMPLATE</div>';
			return '/wordpress/template-canvas.php';
		}
		return $GLOBALS['preview_fixture_root'] . '/classic-doctor.php';
	}
	function wptexturize(string $content): string { return $content; }
	function convert_smilies(string $content): string { return $content; }
	function wp_filter_content_tags(string $content, string $context = ''): string { return $content; }
}

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	final class Draft_Service {
		public const CONTENT_LANGUAGE_META = '_wpsuite_agent_content_language';
		public const REVISION_META = '_wpsuite_agent_revision';
		public function get_owned_draft_for_preview_asset(int $post_id): \WP_Post {
			if (73 !== $post_id) { throw new RuntimeException('Unknown preview draft.'); }
			return new \WP_Post();
		}
		public function get_preview_for_preview_asset(int $post_id): array {
			if (73 !== $post_id) { throw new RuntimeException('Unknown preview draft.'); }
			return array('post_id' => 73, 'modified_gmt' => '2026-09-07T12:00:00Z', 'revision' => '123e4567-e89b-12d3-a456-426614174000', 'validation' => array('valid' => true));
		}
	}
	final class Content_Proposal_Service {
		public const STATE_META = '_wpsuite_agent_proposal_state';
	}

	function do_blocks(string $content): string {
		$GLOBALS['rendered_preview_language_seen'] = apply_filters('smartcloud_composer_rendered_preview_content_language', '');
		if (str_contains($content, 'PROFILE_TEMPLATE')) {
			return str_replace('PROFILE_TEMPLATE', 'Doctor ' . $GLOBALS['post']->ID . ' – ' . $GLOBALS['rendered_preview_language_seen'], $content);
		}
		return str_replace(array('<!-- wp:paragraph -->', '<!-- /wp:paragraph -->'), '', $content);
	}

	function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
		unset($accepted_args);
		$GLOBALS['composer_preview_filters'][$hook][$priority][] = $callback;
		return true;
	}

	function remove_filter(string $hook, callable $callback, int $priority = 10): bool {
		$callbacks = &$GLOBALS['composer_preview_filters'][$hook][$priority];
		if (!is_array($callbacks)) {
			return false;
		}
		foreach ($callbacks as $index => $registered_callback) {
			if ($registered_callback === $callback) {
				unset($callbacks[$index]);
				return true;
			}
		}
		return false;
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
		$filters = $GLOBALS['composer_preview_filters'][$hook] ?? array();
		ksort($filters);
		foreach ($filters as $callbacks) {
			foreach ($callbacks as $callback) {
				$value = $callback($value, ...$arguments);
			}
		}
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

	require_once dirname(__DIR__) . '/src/Security/ActorContext.php';
	require_once dirname(__DIR__) . '/src/Security/ActorIdentity.php';
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
	$assert(false === ($document['body_empty'] ?? null), 'A draft with saved body content must not be marked empty.');
	$assert('static' === ($document['fidelity'] ?? ''), 'The embedded preview must declare static fidelity.');
	$assert('3' === ($document['contract_version'] ?? ''), 'The attested rendered-HTML preview must use contract version 3.');
	$assert('rendered-html' === ($document['source_format'] ?? ''), 'The preview must identify its payload as rendered HTML.');
	$assert(!str_contains((string) ($document['html'] ?? ''), '<!-- wp:'), 'Gutenberg serialization comments must not reach the rendered HTML preview.');
	$assert(!str_contains((string) ($document['html'] ?? ''), '<script'), 'Active script markup must not reach the preview document.');
	$assert(str_contains((string) ($document['html'] ?? ''), 'Hello preview.'), 'Rendered block content must reach the preview document.');
	$assert('ar-SA' === $GLOBALS['rendered_preview_language_seen'], 'Block rendering integrations must receive the authored draft language instead of the MCP request locale.');
	$assert('fallback' === apply_filters('smartcloud_composer_rendered_preview_content_language', 'fallback'), 'The draft-language render context must be removed after preview generation.');
	$resolve_language = new \ReflectionMethod(Rendered_Preview_Service::class, 'content_language_for_post');
	$GLOBALS['preview_post_meta'][73][Draft_Service::CONTENT_LANGUAGE_META] = 'de-DE';
	$assert('de-DE' === $resolve_language->invoke($service, 73), 'Rendered previews must prefer the dedicated authored-language meta.');
	$GLOBALS['preview_post_meta'][73][Draft_Service::CONTENT_LANGUAGE_META] = '';
	$GLOBALS['preview_post_meta'][73]['_wpsuite_agent_proposal_localization'] = array('content_language' => 'hu-HU');
	$assert('hu-HU' === $resolve_language->invoke($service, 73), 'Existing proposals must recover their authored language from the structured localization snapshot.');
	$GLOBALS['preview_post_meta'][73]['_wpsuite_agent_proposal_localization'] = json_encode(array('content_language' => 'fr-FR'));
	$assert('fr-FR' === $resolve_language->invoke($service, 73), 'Legacy JSON localization snapshots must remain a valid preview-language fallback.');
	$assert(!str_contains((string) ($document['html'] ?? ''), '<header><h1>'), 'The preview body must not repeat the title already shown by the preview app chrome.');
	$assert(str_contains((string) ($document['html'] ?? ''), 'page-gatey'), 'The rendered document must carry frontend-compatible body context classes.');
	$assert(hash_equals(hash('sha256', (string) $document['html']), (string) ($document['sha256'] ?? '')), 'The preview hash must cover the exact returned HTML.');
	$volatile_a = '<div data-is-preview="yes" id="runtime_mount_1234567890">Same content</div>';
	$volatile_b = '<div data-is-preview="yes" id="runtime_mount_9876543210">Same content</div>';
	$assert(Rendered_Preview_Service::review_hash($volatile_a) === Rendered_Preview_Service::review_hash($volatile_b), 'Preview-only random mount IDs must not invalidate an unchanged approval.');
	$assert(Rendered_Preview_Service::review_hash($volatile_a) !== Rendered_Preview_Service::review_hash(str_replace('Same content', 'Changed content', $volatile_b)), 'The approval hash must still detect meaningful rendered content changes.');
	$assert(Rendered_Preview_Service::review_hash('<div id="authored_1234567890">Same</div>') !== Rendered_Preview_Service::review_hash('<div id="authored_9876543210">Same</div>'), 'Authored element IDs must remain protected by the approval hash.');
	$assert(Rendered_Preview_Service::review_hash((string) $document['html']) === ($document['review_sha256'] ?? ''), 'The document must expose the exact normalized review hash used by approval decisions.');
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
	$binary = (string) file_get_contents($GLOBALS['preview_fixture_root'] . '/assets/pixel.png');
	$encoded = Rendered_Preview_Service::transport_asset_payload('pa_fixture', 'image', 'image/png', $binary);
	$assert($binary === base64_decode((string) ($encoded['data_base64'] ?? ''), true), 'Image asset bytes must survive the private JSON-safe transport.');
	$assert(strlen($binary) === ($encoded['byte_length'] ?? 0) && hash('sha256', $binary) === ($encoded['sha256'] ?? ''), 'The private asset payload must preserve its exact size and fingerprint.');
	$assert(false !== json_encode($encoded), 'Binary image bytes must not enter WordPress MCP JSON output directly.');
	$assert(!array_key_exists('results', $encoded), 'The private asset helper must not return raw binary in a pseudo image content block.');
	$font_payload = Rendered_Preview_Service::transport_asset_payload('pa_font', 'font', 'font/woff', "wOFF\0\xFF");
	$assert("wOFF\0\xFF" === base64_decode((string) ($font_payload['data_base64'] ?? ''), true), 'Font assets must use the same lossless JSON-safe transport.');
	$css_payload = Rendered_Preview_Service::transport_asset_payload('pa_css', 'stylesheet', 'text/css', '.preview{color:red}');
	$assert('.preview{color:red}' === ($css_payload['css'] ?? ''), 'Sanitized stylesheet assets must remain textual.');
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
	$owned_service = new Rendered_Preview_Service(new Draft_Service());
	$GLOBALS['preview_post_meta'][73][Content_Proposal_Service::STATE_META] = 'ready-for-review';
	$submitted_preview = $owned_service->get(array('post_id' => 73, 'expected_revision' => '123e4567-e89b-12d3-a456-426614174000'));
	$assert(!isset($submitted_preview['rendered_preview_token']) && 'rendered-html' === ($submitted_preview['document']['source_format'] ?? ''), 'The assigned agent must be able to reopen a submitted proposal read-only without minting another submission token.');
	unset($GLOBALS['preview_post_meta'][73][Content_Proposal_Service::STATE_META]);
	$empty_post = new \WP_Post();
	$empty_post->post_type = 'orvosok';
	$empty_post->post_content = '';
	$empty_preview = $service->build_document(
		$empty_post,
		array(
			'preview_url' => 'https://example.test/?p=73&preview=true',
			'modified_gmt' => '2026-09-07T12:00:00Z',
			'revision' => '123e4567-e89b-12d3-a456-426614174000',
		),
		'hu-HU',
		false
	);
	$empty_document = $empty_preview['document'];
	$assert(true === ($empty_document['body_empty'] ?? null), 'An empty saved body must be machine-readable even when the WordPress template supplies the page.');
	$assert('content' === $empty_document['scope'] && 'static' === $empty_document['fidelity'], 'An empty body must not pretend to be a complete template preview.');
	$assert(in_array('empty_post_content', array_column($empty_document['warnings'], 'code'), true), 'An empty body must carry an explicit template/field warning.');
	$assert('https://example.test/?p=73&preview=true' === $empty_preview['preview_url'], 'The authorized WordPress preview link must remain available for the complete page.');
	$assert(!str_contains($empty_document['html'], 'Rendered & safe'), 'The inline document must not invent template content for an empty post body.');
	$GLOBALS['preview_template_post'] = $empty_post;
	$GLOBALS['preview_template_enabled'] = true;
	$GLOBALS['wp_query'] = 'prior-query';
	$block_preview = $service->build_document($empty_post, array('revision' => 'template-test', 'modified_gmt' => '2026-09-07T12:00:00Z'), 'hu-HU', false);
	$block_document = $block_preview['document'];
	$assert('template' === $block_document['scope'], 'An empty doctor post must render its post-type block template.');
	$assert(str_contains($block_document['html'], 'Doctor 73 – hu-HU'), 'The selected template must receive the real queried post and authored language.');
	$assert(!in_array('empty_post_content', array_column($block_document['warnings'], 'code'), true), 'A rendered template must not be labelled as an empty page.');
	$assert('prior-query' === $GLOBALS['wp_query'], 'Preview rendering must restore the caller query.');
	$assert('fallback' === apply_filters('smartcloud_composer_rendered_preview_content_language', 'fallback'), 'Template rendering must clean up its language context.');
	file_put_contents($GLOBALS['preview_fixture_root'] . '/classic-doctor.php', '<?php echo "<section class=\\"classic-doctor\\">Classic doctor " . $GLOBALS["post"]->ID . "</section>";');
	$GLOBALS['preview_block_theme'] = false;
	$classic_preview = $service->build_document($empty_post, array('revision' => 'classic-test', 'modified_gmt' => '2026-09-07T12:00:00Z'), 'hu-HU', false);
	$assert('template' === $classic_preview['document']['scope'] && str_contains($classic_preview['document']['html'], 'Classic doctor 73'), 'A classic post-type PHP template must also render through WordPress query context.');
	$GLOBALS['preview_template_enabled'] = false;

	$abilities = (new \ReflectionClass(Abilities::class))->newInstanceWithoutConstructor();
	$preview_schema = (new \ReflectionMethod(Abilities::class, 'rendered_preview_output_schema'))->invoke($abilities)['properties']['document'] ?? array();
	$assert(in_array('template', $preview_schema['properties']['scope']['enum'] ?? array(), true), 'The MCP output schema must admit full WordPress template previews.');
	$assert(isset($preview_schema['properties']['review_sha256']) && in_array('review_sha256', $preview_schema['required'] ?? array(), true), 'The MCP output schema must carry the locked review fingerprint.');
	$field_update_schema = $abilities->semantic_field_update_schema();
	$media_update_schema = $abilities->semantic_media_update_schema();
	$slot_insert_schema = $abilities->semantic_slot_insert_schema();
	$assert(in_array('field_id', $field_update_schema['required'] ?? array(), true), 'Semantic field updates must use the canonical field_id input.');
	$assert(isset($field_update_schema['properties']['pattern_instance_id']), 'Semantic field updates must accept a stable pattern instance address.');
	$assert(in_array('field_id', $media_update_schema['required'] ?? array(), true), 'Semantic media updates must use the canonical field_id input.');
	$assert(isset($media_update_schema['properties']['pattern_instance_id']), 'Semantic media updates must accept a stable pattern instance address.');
	$assert(isset($slot_insert_schema['properties']['pattern_instance_id']), 'Slot mutations must be able to select one repeated pattern-owned slot.');
	$assert(isset($slot_insert_schema['properties']['block']['properties']['pattern']), 'Semantic slots must accept an allow-listed synced pattern specification.');
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
		'smartcloud-agent-composer/rendered-preview-app' => 'ui://smartcloud-agent-composer/rendered-preview/v8.html',
		'smartcloud-agent-composer/rendered-preview-app-v7' => 'ui://smartcloud-agent-composer/rendered-preview/v7.html',
		'smartcloud-agent-composer/rendered-preview-app-v6' => 'ui://smartcloud-agent-composer/rendered-preview/v6.html',
		'smartcloud-agent-composer/rendered-preview-app-v5' => 'ui://smartcloud-agent-composer/rendered-preview/v5.html',
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
	$assert(str_contains($latest_app_html, 'function assetResult') && str_contains($latest_app_html, 'value.structuredContent'), 'The preview app must read the WordPress MCP structured asset payload.');
	$assert(str_contains($latest_app_html, 'data.data_base64') && str_contains($latest_app_html, 'blob.size!==asset.byte_length'), 'The preview app must reject empty or mismatched binary assets before creating image URLs.');
	$assert(str_contains($latest_app_html, "asset.kind==='image'?'data:'") && str_contains($latest_app_html, 'if(!result.imageUrl)blobUrls.add(url)'), 'The preview app must use CSP-compatible data URLs for images and revoke only object URLs.');
	$assert(str_contains($latest_app_html, 'smartcloud-preview-asset://'), 'The rendered preview app must rewrite private CSS dependency placeholders to local asset URLs.');
	$assert(!str_contains($latest_app_html, 'expected_modified_gmt:doc.modified_gmt'), 'The preview app must not bind read-only asset delivery to a mutable timestamp.');
	$assert(str_contains($latest_app_html, "documentKey===lastDocumentKey"), 'Duplicate host delivery channels must not start duplicate asset batches for the same preview document.');
	$assert(str_contains($latest_app_html, 'Math.min(2,items.length)'), 'The preview app must keep private MCP asset request concurrency bounded.');
	$assert(str_contains($latest_app_html, "document.createElement('body')"), 'The isolated preview must recreate a body context for theme selectors.');
	$assert(str_contains($latest_app_html, "shell.classList.add('smartcloud-composer-preview-document')"), 'Every recreated body must receive the preview sizing class alongside its frontend context classes.');
	$assert(str_contains($latest_app_html, 'height:auto!important'), 'The recreated body must grow with long content instead of letting its background stop at the preview viewport height.');
	$assert(str_contains($latest_app_html, 'height:var(--composer-app-height,680px)'), 'The preview app must reserve a stable host-bounded height before rendered content arrives.');
	$assert(str_contains($latest_app_html, 'grid-template-rows:auto minmax(0,1fr) auto'), 'The preview app must keep its chrome fixed around the independently scrolling preview rail.');
	$assert(str_contains($latest_app_html, 'document.fonts?.ready'), 'The preview app must wait for font loading before revealing the rendered document.');
	$assert(str_contains($latest_app_html, "preview.dataset.ready='true'"), 'The preview app must reveal content only after its styles and assets settle.');
	$assert(!str_contains($latest_app_html, 'ResizeObserver'), 'The preview app must not report content-driven intrinsic height changes.');
	$assert(str_contains($latest_app_html, 'max-height:112px'), 'The preview warning list must not grow the conversation card without a bound.');
	$assert(str_contains($latest_app_html, "content.className='wp-block-post-content'"), 'The isolated preview must recreate the WordPress content wrapper.');
	$assert(str_contains($latest_app_html, 'doc.body_empty') && str_contains($latest_app_html, 'Open full WordPress preview'), 'An empty rendered-preview app must explain missing template content and expose the full WordPress preview.');
	$assert(str_contains($latest_app_html, 'safePreviewUrl') && str_contains($latest_app_html, "['http:','https:']"), 'The full-preview action must accept only safe HTTP(S) destinations.');
	$assert(str_contains($latest_app_html, 'function safeStyle'), 'Safe Gutenberg inline presentation styles must remain available in the rendered preview.');
	$assert(str_contains($latest_app_html, 'audio,video,source,track,picture'), 'The client sanitizer must remove passive media elements that could fetch external resources.');
	$assert(str_contains($latest_app_html, "version:'8.0.0'"), 'Every resource alias must serve the latest v8 preview app.');

	$register_approval_tools = new \ReflectionMethod(Abilities::class, 'register_publish_approval_private_abilities');
	$register_approval_tools->invoke($abilities);
	$register_approval_resource = new \ReflectionMethod(Abilities::class, 'register_publish_approval_resource');
	$register_approval_resource->invoke($abilities);
	$decision_ability = $GLOBALS['registered_abilities']['smartcloud-agent-composer/decide-publish-approval'] ?? array();
	$open_ability = $GLOBALS['registered_abilities']['smartcloud-agent-composer/open-publish-approval'] ?? array();
	$approval_asset_ability = $GLOBALS['registered_abilities']['smartcloud-agent-composer/get-publish-approval-asset'] ?? array();
	$assert(array('app') === ($decision_ability['meta']['mcp']['_meta']['ui']['visibility'] ?? null), 'The publication decision must be app-only.');
	$assert('private' === ($decision_ability['meta']['mcp']['_meta']['openai/visibility'] ?? ''), 'The publication decision must be absent from the model-facing tool surface.');
	$assert(true === ($decision_ability['meta']['mcp']['_meta']['openai/widgetAccessible'] ?? false), 'The publication app must be allowed to invoke its private decision tool.');
	$assert(array('app') === ($open_ability['meta']['mcp']['_meta']['ui']['visibility'] ?? null), 'The short-lived approval session must be delivered only to the app.');
	$assert(array('app') === ($approval_asset_ability['meta']['mcp']['_meta']['ui']['visibility'] ?? null), 'Publication preview assets must be app-only.');
	$approval_resource = $GLOBALS['registered_abilities']['smartcloud-agent-composer/publish-approval-app'] ?? array();
	$approval_contents = ($approval_resource['execute_callback'])();
	$assert('ui://smartcloud-agent-composer/publish-approval/v8.html' === ($approval_contents[0]['uri'] ?? ''), 'Publication review must use the latest versioned MCP App resource URI.');
	$approval_v7_resource = $GLOBALS['registered_abilities']['smartcloud-agent-composer/publish-approval-app-v7'] ?? array();
	$approval_v7_contents = ($approval_v7_resource['execute_callback'])();
	$assert('ui://smartcloud-agent-composer/publish-approval/v7.html' === ($approval_v7_contents[0]['uri'] ?? ''), 'The v7 publication review URI must remain a compatibility alias.');
	$approval_v6_resource = $GLOBALS['registered_abilities']['smartcloud-agent-composer/publish-approval-app-v6'] ?? array();
	$approval_v6_contents = ($approval_v6_resource['execute_callback'])();
	$assert('ui://smartcloud-agent-composer/publish-approval/v6.html' === ($approval_v6_contents[0]['uri'] ?? ''), 'The v6 publication review URI must remain a compatibility alias.');
	$approval_v5_resource = $GLOBALS['registered_abilities']['smartcloud-agent-composer/publish-approval-app-v5'] ?? array();
	$approval_v5_contents = ($approval_v5_resource['execute_callback'])();
	$assert('ui://smartcloud-agent-composer/publish-approval/v5.html' === ($approval_v5_contents[0]['uri'] ?? ''), 'The v5 publication review URI must remain a compatibility alias.');
	$approval_v4_resource = $GLOBALS['registered_abilities']['smartcloud-agent-composer/publish-approval-app-v4'] ?? array();
	$approval_v4_contents = ($approval_v4_resource['execute_callback'])();
	$assert('ui://smartcloud-agent-composer/publish-approval/v4.html' === ($approval_v4_contents[0]['uri'] ?? ''), 'The v4 publication review URI must remain a compatibility alias.');
	$approval_v3_resource = $GLOBALS['registered_abilities']['smartcloud-agent-composer/publish-approval-app-v3'] ?? array();
	$approval_v3_contents = ($approval_v3_resource['execute_callback'])();
	$assert('ui://smartcloud-agent-composer/publish-approval/v3.html' === ($approval_v3_contents[0]['uri'] ?? ''), 'The v3 publication review URI must remain a compatibility alias.');
	$approval_v2_resource = $GLOBALS['registered_abilities']['smartcloud-agent-composer/publish-approval-app-v2'] ?? array();
	$approval_v2_contents = ($approval_v2_resource['execute_callback'])();
	$assert('ui://smartcloud-agent-composer/publish-approval/v2.html' === ($approval_v2_contents[0]['uri'] ?? ''), 'The v2 publication review URI must remain a compatibility alias.');
	$approval_v1_resource = $GLOBALS['registered_abilities']['smartcloud-agent-composer/publish-approval-app-v1'] ?? array();
	$approval_v1_contents = ($approval_v1_resource['execute_callback'])();
	$assert('ui://smartcloud-agent-composer/publish-approval/v1.html' === ($approval_v1_contents[0]['uri'] ?? ''), 'The v1 publication review URI must remain a compatibility alias.');
	$approval_html = (string) ($approval_contents[0]['text'] ?? '');
	$assert(str_contains($approval_html, 'function assetResult') && str_contains($approval_html, 'data.data_base64'), 'The publication approval app must use the same JSON-safe private asset transport.');
	$assert(str_contains($approval_html, 'Approve and publish'), 'The inline publication app must expose an explicit human approval control.');
	$assert(str_contains($approval_html, 'function askDecision'), 'The first approval or rejection click must open an in-app confirmation step.');
	$assert(str_contains($approval_html, 'function cancelConfirmation'), 'The in-app decision confirmation must remain cancellable without invoking a tool.');
	$assert(str_contains($approval_html, 'class="confirm-backdrop"'), 'The confirmation must cover the approval card as an in-app modal layer.');
	$assert(str_contains($approval_html, 'aria-modal="true"'), 'The confirmation layer must expose modal dialog semantics to assistive technology.');
	$assert(str_contains($approval_html, "event.key==='Escape'"), 'The in-app modal must support keyboard cancellation.');
	$assert(str_contains($approval_html, 'No saved body content'), 'An empty draft body must render an explicit approval-preview empty state.');
	$assert(str_contains($approval_html, 'not a locked approval snapshot') && str_contains($approval_html, 'Open full WordPress preview'), 'Approval review must expose the full WordPress preview without claiming it is the locked inline snapshot.');
	$assert(str_contains($approval_html, 'hasRenderableBody'), 'The approval app must distinguish an empty body from text or visual HTML content.');
	$assert(str_contains($approval_html, "approve.addEventListener('click',()=>askDecision('approve'))"), 'The primary publish button must not invoke the private decision helper directly.');
	$assert(str_contains($approval_html, "reject.addEventListener('click',()=>askDecision('reject'))"), 'The primary reject button must not invoke the private decision helper directly.');
	$assert(str_contains($approval_html, 'smartcloud-agent-composer-decide-publish-approval'), 'The inline publication app must call only the private decision helper.');
	$assert(str_contains($approval_html, 'smartcloud-agent-composer-open-publish-approval'), 'The inline publication app must acquire its short-lived token through an app-only helper.');
	$assert(str_contains($approval_html, 'confirm_decision:true'), 'The inline publication app must send an explicit human confirmation marker.');
	$assert(str_contains($approval_html, 'smartcloud-agent-composer-get-publish-approval-asset'), 'The inline publication app must load preview assets through the authenticated MCP bridge.');
	$assert(str_contains($approval_html, 'openExternal'), 'The firewall-dependent WordPress approval page must remain only an explicit fallback link.');
	$assert(str_contains($approval_html, 'statusMessage'), 'The approval app must render terminal approval states without exposing a raw repeated-decision error.');
	$assert(str_contains($approval_html, "state==='pending'"), 'Only pending approvals may keep decision controls enabled.');
	$assert(str_contains($approval_html, 'height:var(--composer-app-height,680px)'), 'The approval app must reserve a stable host-bounded height before rendered content arrives.');
	$assert(str_contains($approval_html, 'grid-template-rows:auto minmax(0,1fr) auto'), 'The approval app must keep the summary and decision controls fixed around the scrolling preview.');
	$assert(str_contains($approval_html, 'document.fonts?.ready'), 'The approval app must wait for font loading before revealing the exact revision.');
	$assert(str_contains($approval_html, "preview.dataset.ready='true'"), 'The approval app must reveal the exact revision only after its assets settle.');
	$assert(!str_contains($approval_html, 'ResizeObserver'), 'The approval app must not report content-driven intrinsic height changes.');
	$assert(str_contains($approval_html, "asset.kind==='image'?'data:'"), 'The publication review app must display images using CSP-compatible data URLs.');
	$assert(str_contains($approval_html, "version:'8.0.0'"), 'Every publication review resource alias must serve the latest v8 approval UI.');

	foreach (glob($preview_fixture_root . '/assets/*') ?: array() as $fixture) {
		@unlink($fixture);
	}
	@rmdir($preview_fixture_root . '/assets');
	@rmdir($preview_fixture_root);

	echo "rendered-preview-contract: ok\n";
}
