<?php

declare(strict_types=1);

namespace {
	function wp_upload_dir(?string $time = null, bool $create_dir = true): array {
		return array('baseurl' => 'https://media.example.test/uploads', 'error' => false);
	}

	function site_url(string $path = ''): string {
		return 'https://admin.example.test' . $path;
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
	$assert(!str_contains((string) ($document['html'] ?? ''), '<script'), 'Active script markup must not reach the preview document.');
	$assert(str_contains((string) ($document['html'] ?? ''), 'Hello preview.'), 'Rendered block content must reach the preview document.');
	$assert(hash_equals(hash('sha256', (string) $document['html']), (string) ($document['sha256'] ?? '')), 'The preview hash must cover the exact returned HTML.');
	$assert(strlen((string) $document['html']) === ($document['byte_length'] ?? -1), 'The preview byte length must cover the exact returned HTML.');
	$assert(in_array('https://media.example.test', Rendered_Preview_Service::allowed_asset_origins(), true), 'The configured uploads origin must be admitted to the MCP Apps CSP.');
	$assert(in_array('https://admin.example.test', Rendered_Preview_Service::allowed_asset_origins(), true), 'A distinct WordPress site origin must be admitted to the MCP Apps CSP.');
	$assert('active_markup_removed' === ($document['warnings'][1]['code'] ?? ''), 'Sanitizer changes must be reported to the client.');

	echo "rendered-preview-contract: ok\n";
}
