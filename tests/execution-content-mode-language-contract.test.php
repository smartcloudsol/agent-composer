<?php

declare(strict_types=1);

namespace {
	function absint(mixed $value): int { return abs((int) $value); }
	function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $value)); }
}

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	$GLOBALS['semantic_manifest'] = array();
	$GLOBALS['semantic_tree'] = array();

	function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $value)); }
	function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
	function wp_strip_all_tags(string $value, bool $remove_breaks = false): string { return strip_tags($value); }
	function absint(mixed $value): int { return abs((int) $value); }
	function apply_filters(string $hook, mixed $value): mixed { return 'smartcloud_composer_semantic_slot_manifest' === $hook ? $GLOBALS['semantic_manifest'] : $value; }
	function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
	function esc_attr(string $value): string { return esc_html($value); }
	function esc_url(string $value, array $protocols = array()): string { return preg_match('#^(?:https?|mailto|tel):#', $value) ? $value : ''; }
	function parse_blocks(string $content): array {
		$decoded = json_decode($content, true);
		return is_array($decoded) ? $decoded : $GLOBALS['semantic_tree'];
	}
	function serialize_blocks(array $blocks): string { return (string) json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

	require_once dirname(__DIR__) . '/src/Execution/Execution_Exception.php';
	require_once dirname(__DIR__) . '/src/Execution/Excerpt_Policy.php';
	require_once dirname(__DIR__) . '/src/Execution/Config_Repository.php';
	require_once dirname(__DIR__) . '/src/Execution/Semantic_Slot_Materializer.php';
	require_once dirname(__DIR__) . '/src/Execution/Content_Language_Validator.php';

	function mode_assert(bool $condition, string $message): void {
		if (! $condition) throw new RuntimeException($message);
	}
	function mode_error(string $code, callable $operation, string $message): void {
		try { $operation(); } catch (Execution_Exception $error) {
			mode_assert($code === $error->get_execution_code(), $message . ': wrong code ' . $error->get_execution_code());
			return;
		}
		throw new RuntimeException($message . ': no exception');
	}
	function fingerprint(string $value): string {
		return 'sha256:' . hash('sha256', strtolower(trim((string) preg_replace('/\s+/', ' ', $value))));
	}

	$repository = (new \ReflectionClass(Config_Repository::class))->newInstanceWithoutConstructor();
	$policy = array(
		'content_language' => 'hu-HU', 'content_language_enforcement' => 'strict', 'content_language_exceptions' => array('SmartCloud'),
		'post_type_contract' => array('doctor' => 'orvosok', 'article' => 'post'),
		'allowed_pattern_namespaces' => array('smartcloud-agent-canvas'),
		'constraints' => array('exactly_one_h1' => true, 'inline_css' => false, 'custom_html' => false, 'shortcodes' => false, 'external_embeds' => false, 'theme_presets_only' => true, 'maximum_words' => 3000),
		'seo_contract' => array(
			'required_fields' => array('excerpt', 'meta_description'),
			'meta_description' => array('minimum_characters' => 120, 'maximum_characters' => 160),
		),
		'block_extensions' => array(
			'allowed_core_blocks' => array('core/image', 'core/query'),
			'allowed_plugin_namespaces' => array('smartcloud-flow'),
			'captioned_media_image_materializer' => false,
		),
	);
	$property = new \ReflectionProperty(Config_Repository::class, 'design_policy');
	$property->setAccessible(true);
	$property->setValue($repository, $policy);
	$normalize = new \ReflectionMethod(Config_Repository::class, 'normalize_blueprint');
	$normalize->setAccessible(true);

	$document = $normalize->invoke($repository, array(
		'page_type' => 'article', 'composition_mode' => 'document', 'target_post_type' => 'post',
		'allowed_patterns' => array('smartcloud-agent-canvas/page-body-starter'),
		'required_sequence' => array('smartcloud-agent-canvas/page-body-starter'),
		'allowed_blocks' => array('core/group', 'core/heading', 'core/paragraph', 'core/embed'),
		'target_template' => array('mode' => 'hierarchy', 'file' => 'templates/single.html'),
		'constraints' => array(
			'exactly_one_h1' => false,
			'inline_css' => true,
			'custom_html' => false,
			'shortcodes' => true,
			'external_embeds' => true,
			'theme_presets_only' => false,
			'maximum_words' => 9000,
		),
		'seo_contract' => array(
			'required_fields' => array('excerpt'),
			'meta_description' => array('maximum_characters' => 180),
		),
		'block_extensions' => array(
			'allowed_core_blocks' => array('core/image'),
			'captioned_media_image_materializer' => true,
		),
	), 'article');
	mode_assert(false === $document['constraints']['exactly_one_h1'], 'An explicit Blueprint H1 rule must override the Site Contract default.');
	mode_assert(true === $document['constraints']['inline_css'], 'An explicit Blueprint inline-CSS rule must override the Site Contract default.');
	mode_assert(true === $document['constraints']['shortcodes'], 'An explicit Blueprint shortcode rule must override the Site Contract default.');
	mode_assert(true === $document['constraints']['external_embeds'], 'An explicit Blueprint embed rule must override the Site Contract default.');
	mode_assert(false === $document['constraints']['theme_presets_only'], 'An explicit Blueprint theme-preset rule must override the Site Contract default.');
	mode_assert(9000 === $document['constraints']['maximum_words'], 'An explicit Blueprint word ceiling must override the Site Contract default.');
	mode_assert(false === $document['constraints']['custom_html'], 'Custom HTML must remain a non-configurable execution invariant.');
	mode_assert(array('excerpt') === $document['seo_contract']['required_fields'], 'An explicit Blueprint SEO list must replace the inherited list completely.');
	mode_assert(120 === $document['seo_contract']['meta_description']['minimum_characters'], 'Unspecified nested SEO values must remain inherited.');
	mode_assert(180 === $document['seo_contract']['meta_description']['maximum_characters'], 'An explicit nested Blueprint SEO value must override its inherited default.');
	mode_assert(array('core/image') === $document['block_extensions']['allowed_core_blocks'], 'An explicit Blueprint extension list must replace the inherited list completely.');
	mode_assert(true === $document['block_extensions']['captioned_media_image_materializer'], 'An explicit Blueprint extension switch must override its inherited default.');
	mode_assert(array('smartcloud-flow') === $document['block_extensions']['allowed_plugin_namespaces'], 'Unspecified Blueprint extension values must remain inherited.');

	$structured = $normalize->invoke($repository, array(
		'page_type' => 'doctor', 'composition_mode' => 'structured-record', 'target_post_type' => 'orvosok',
		'allowed_patterns' => array(), 'required_sequence' => array(), 'allowed_blocks' => array(),
		'target_template' => array('mode' => 'hierarchy', 'file' => 'templates/single.html'),
	), 'doctor');
	mode_assert('structured-record' === $structured['composition_mode'], 'Structured mode must normalize.');
	mode_assert(false === $structured['constraints']['exactly_one_h1'], 'Structured records must not require a body H1.');

	mode_error('blueprint_has_no_patterns', static function () use ($normalize, $repository): void {
		$normalize->invoke($repository, array(
			'page_type' => 'article', 'composition_mode' => 'document', 'target_post_type' => 'post',
			'allowed_patterns' => array(), 'required_sequence' => array(), 'allowed_blocks' => array(),
			'target_template' => array('mode' => 'hierarchy', 'file' => 'templates/single.html'),
		), 'article');
	}, 'Documents with no governed composition must fail.');
	mode_error('blueprint_custom_html_block_forbidden', static function () use ($normalize, $repository): void {
		$normalize->invoke($repository, array(
			'page_type' => 'article', 'composition_mode' => 'document', 'target_post_type' => 'post',
			'allowed_patterns' => array('smartcloud-agent-canvas/page-body-starter'),
			'required_sequence' => array('smartcloud-agent-canvas/page-body-starter'),
			'allowed_blocks' => array('core/group', 'core/html'),
			'target_template' => array('mode' => 'hierarchy', 'file' => 'templates/single.html'),
		), 'article');
	}, 'Unsupported Custom HTML must fail explicitly instead of being removed silently.');
	mode_error('blueprint_custom_html_forbidden', static function () use ($normalize, $repository): void {
		$normalize->invoke($repository, array(
			'page_type' => 'article', 'composition_mode' => 'document', 'target_post_type' => 'post',
			'allowed_patterns' => array('smartcloud-agent-canvas/page-body-starter'),
			'required_sequence' => array('smartcloud-agent-canvas/page-body-starter'),
			'allowed_blocks' => array('core/group', 'core/paragraph'),
			'target_template' => array('mode' => 'hierarchy', 'file' => 'templates/single.html'),
			'constraints' => array('custom_html' => true),
		), 'article');
	}, 'Unsupported Custom HTML constraints must fail explicitly instead of being replaced silently.');

	$GLOBALS['semantic_manifest'] = array('components' => array('hero.service' => array(
		'patterns' => array('smartcloud-agent-canvas/hero-service'),
		'slots' => array(
			'heading' => array('class' => 'slot-heading', 'type' => 'heading', 'required' => true, 'required_for_agent' => true, 'on_missing' => 'reject', 'target' => array('strategy' => 'class-occurrence', 'occurrence' => 1), 'fallback_fingerprint' => fingerprint('Expert help')),
			'primary_action' => array('class' => 'slot-primary', 'type' => 'action', 'required' => false, 'required_for_agent' => true, 'on_missing' => 'reject', 'target' => array('strategy' => 'class-occurrence', 'occurrence' => 1), 'fallback_fingerprint' => fingerprint('Get started')),
			'secondary_action' => array('class' => 'slot-secondary', 'type' => 'action', 'required' => false, 'required_for_agent' => false, 'on_missing' => 'remove', 'target' => array('strategy' => 'class-occurrence', 'occurrence' => 1), 'fallback_fingerprint' => fingerprint('Learn more')),
		),
	)));
	$GLOBALS['semantic_tree'] = array(array(
		'blockName' => 'core/group', 'attrs' => array(), 'innerHTML' => '<div></div>',
		'innerContent' => array('<div>', null, null, null, '</div>'),
		'innerBlocks' => array(
			array('blockName' => 'core/heading', 'attrs' => array('className' => 'slot-heading'), 'innerHTML' => '<h1>Expert help</h1>', 'innerContent' => array('<h1>Expert help</h1>'), 'innerBlocks' => array()),
			array('blockName' => 'core/button', 'attrs' => array('className' => 'slot-primary'), 'innerHTML' => '<div><a href="#">Get started</a></div>', 'innerContent' => array('<div><a href="#">Get started</a></div>'), 'innerBlocks' => array()),
			array('blockName' => 'core/button', 'attrs' => array('className' => 'slot-secondary'), 'innerHTML' => '<div><a href="#">Learn more</a></div>', 'innerContent' => array('<div><a href="#">Learn more</a></div>'), 'innerBlocks' => array()),
		),
	));

	$slots = new Semantic_Slot_Materializer();
	mode_error('missing_semantic_slot', static fn() => $slots->materialize('smartcloud-agent-canvas/hero-service', 'pattern', array()), 'A literal fallback cannot satisfy a required semantic slot.');
	$rendered = $slots->materialize('smartcloud-agent-canvas/hero-service', 'pattern', array(
		'heading' => 'Szakértő segítség',
		'primary_action' => array('label' => 'Időpontfoglalás', 'url' => 'https://example.test/foglalas'),
	));
	mode_assert(str_contains($rendered, 'Szakértő segítség'), 'Hungarian heading must replace the fallback.');
	mode_assert(str_contains($rendered, 'Időpontfoglalás'), 'Structured action must replace label and URL.');
	mode_assert(! str_contains($rendered, 'Learn more'), 'Omitted optional action must be removed.');
	mode_assert($rendered === serialize_blocks(parse_blocks($rendered)), 'Materialized blocks must survive a parse/serialize round trip.');
	mode_error('pattern_fallback_copy_remaining', static fn() => $slots->assert_no_registered_fallbacks('smartcloud-agent-canvas/hero-service', 'pattern'), 'Registered fallback fingerprints must block unchanged public copy.');

	$language = (new \ReflectionClass(Content_Language_Validator::class))->newInstanceWithoutConstructor();
	$strict = array('content_language' => 'hu-HU', 'content_language_enforcement' => 'strict', 'content_language_exceptions' => array('SmartCloud'));
	mode_assert(! empty($language->issues_for_policy($strict, 'This is the clear next step for your service and the people who use it.')), 'Substantial English text must fail strict hu-HU validation.');
	mode_assert(empty($language->issues_for_policy($strict, 'A SmartCloud rendszer magyar nyelvű, ellenőrzött szakmai adatokat kezel.')), 'Approved brands in Hungarian copy must not create a false positive.');

	$draftSource = (string) file_get_contents(dirname(__DIR__) . '/src/Execution/Draft_Service.php');
	$assemblerSource = (string) file_get_contents(dirname(__DIR__) . '/src/Execution/Pattern_Assembler.php');
	$validatorSource = (string) file_get_contents(dirname(__DIR__) . '/src/Execution/Page_Validator.php');
	mode_assert(str_contains($draftSource, 'structured_record_block_mutation_forbidden'), 'Structured records must reject later body mutation.');
	mode_assert(str_contains($assemblerSource, "'composition_mode' => 'structured-record'"), 'Zero-section structured assembly must be explicit.');
	mode_assert(str_contains($assemblerSource, 'add_pattern_metadata'), 'Document assembly must retain pattern identity metadata.');
	mode_assert(str_contains($validatorSource, "! empty( \$blueprint['constraints']['theme_presets_only'] )"), 'Theme-preset validation must obey the effective Blueprint switch.');
	mode_assert(str_contains($validatorSource, "'external_embed_forbidden'"), 'External-embed validation must obey the effective Blueprint switch.');

	echo "content-mode-language-contract: ok\n";
}
