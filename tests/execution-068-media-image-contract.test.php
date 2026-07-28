<?php

declare(strict_types=1);

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	function esc_attr(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	function sanitize_key(string $value): string {
		return strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $value));
	}

	function wp_kses(string $value, array $allowed_html): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $value;
	}

	require_once dirname(__DIR__) . '/src/Execution/Execution_Exception.php';
	require_once dirname(__DIR__) . '/src/Execution/Abilities.php';
	require_once dirname(__DIR__) . '/src/Execution/Block_Tree_Service.php';

	function assert_same(mixed $expected, mixed $actual, string $message): void {
		if ($expected !== $actual) {
			throw new RuntimeException(
				$message . sprintf(' Expected %s, got %s.', var_export($expected, true), var_export($actual, true))
			);
		}
	}

	function assert_true(bool $condition, string $message): void {
		if (! $condition) {
			throw new RuntimeException($message);
		}
	}

	$abilities = (new \ReflectionClass(Abilities::class))->newInstanceWithoutConstructor();

	$schema_method = new \ReflectionMethod(Abilities::class, 'media_image_schema');
	$schema_method->setAccessible(true);
	$schema = $schema_method->invoke($abilities);
	$trigger_schema = $schema['properties']['gallery_trigger'] ?? array();
	assert_same(
		array('modal_id', 'gallery_id', 'index'),
		$trigger_schema['required'] ?? null,
		'The media Ability must require the complete structured Flow trigger.'
	);

	$trigger_method = new \ReflectionMethod(Abilities::class, 'media_gallery_trigger_classes');
	$trigger_method->setAccessible(true);
	$classes = $trigger_method->invoke(
		$abilities,
		array('modal_id' => 'media-modal', 'gallery_id' => 'product-art', 'index' => 2)
	);
	assert_same(
		array(
			'wps-flow-modal-open--media-modal',
			'wps-flow-gallery-target--product-art',
			'wps-flow-gallery-index--2',
		),
		$classes,
		'The Flow trigger classes must be deterministic.'
	);

	$size_method = new \ReflectionMethod(Abilities::class, 'media_image_size_slug');
	$size_method->setAccessible(true);
	assert_same('full', $size_method->invoke($abilities, array('size_slug' => 'medium'), 'agency'), 'Non-post media must always use the full source.');
	assert_same('large', $size_method->invoke($abilities, array(), 'post'), 'Post media must default to the large source for the canonical 640px presentation.');
	assert_same('full', $size_method->invoke($abilities, array('size_slug' => 'full'), 'post'), 'Posts may explicitly request another registered source size.');

	$caption_method = new \ReflectionMethod(Abilities::class, 'media_image_caption');
	$caption_method->setAccessible(true);
	assert_same(
		'First line<br>Second line<br>Third line',
		$caption_method->invoke($abilities, "First line\r\nSecond line\nThird line"),
		'The Composer must normalize Media Library caption line breaks like core/image.'
	);

	try {
		$trigger_method->invoke(
			$abilities,
			array('modal_id' => 'bad id', 'gallery_id' => 'product-art', 'index' => 2)
		);
		throw new RuntimeException('An unsafe Flow identifier must fail.');
	} catch (\ReflectionException $error) {
		throw $error;
	} catch (\Throwable $error) {
		$execution_error = $error instanceof Execution_Exception
			? $error
			: ($error->getPrevious() instanceof Execution_Exception ? $error->getPrevious() : null);
		assert_true($execution_error instanceof Execution_Exception, 'The invalid trigger must raise Execution_Exception.');
		assert_same('invalid_gallery_trigger', $execution_error->get_execution_code(), 'The invalid trigger must use a stable code.');
	}

	$image_block = array(
		'blockName'    => 'core/image',
		'attrs'        => array('id' => 42, 'sizeSlug' => 'large', 'linkDestination' => 'none'),
		'innerBlocks'  => array(),
		'innerHTML'    => '<figure class="wp-block-image size-large"><img src="https://example.com/image.png" alt="" class="wp-image-42"/></figure>',
		'innerContent' => array('<figure class="wp-block-image size-large"><img src="https://example.com/image.png" alt="" class="wp-image-42"/></figure>'),
	);
	$placement_method = new \ReflectionMethod(Abilities::class, 'media_placement_block');
	$placement_method->setAccessible(true);
	$placement = $placement_method->invoke($abilities, $image_block, 'wps-explanatory-media');
	assert_same('core/group', $placement['blockName'] ?? null, 'Placement must use a native Group.');
	assert_same('default', $placement['attrs']['layout']['type'] ?? null, 'Placement must not constrain inner width.');
	assert_same('wps-explanatory-media', $placement['attrs']['className'] ?? null, 'Placement class must survive in attributes.');
	assert_same(array($image_block), $placement['innerBlocks'] ?? null, 'Placement must contain the canonical Image block.');
	assert_same(1, count(array_filter($placement['innerContent'] ?? array(), static fn(mixed $part): bool => null === $part)), 'Placement must expose one canonical child placeholder.');

	$source = file_get_contents(dirname(__DIR__) . '/src/Execution/Abilities.php');
	assert_true(is_string($source), 'The Abilities source must be readable.');
	$image_start = strpos($source, '$image_html');
	$image_end = strpos($source, "if ( 'media' === \$link_destination )", $image_start === false ? 0 : $image_start);
	assert_true(is_int($image_start) && is_int($image_end), 'The image materializer source segment must be found.');
	$image_markup_source = substr($source, $image_start, $image_end - $image_start);
	assert_true(str_contains($image_markup_source, ' title="'), 'The materialized Image markup must synchronize the Media Library title.');
	assert_true(! str_contains($image_markup_source, ' width="'), 'Unsynchronized width HTML must not be materialized.');
	assert_true(! str_contains($image_markup_source, ' height="'), 'Unsynchronized height HTML must not be materialized.');
	$block_attrs_start = strpos($source, '$block_attrs = array(', $image_end);
	$block_attrs_end = strpos($source, '$block        = array(', $block_attrs_start === false ? 0 : $block_attrs_start);
	assert_true(is_int($block_attrs_start) && is_int($block_attrs_end), 'The Image delimiter attribute segment must be found.');
	$block_attrs_source = substr($source, $block_attrs_start, $block_attrs_end - $block_attrs_start);
	foreach (array('url', 'alt', 'caption', 'title', 'href') as $source_attribute) {
		assert_true(
			! str_contains($block_attrs_source, "['" . $source_attribute . "']"),
			'The core/image delimiter must omit HTML-sourced attribute: ' . $source_attribute
		);
	}

	$tree_service = (new \ReflectionClass(Block_Tree_Service::class))->newInstanceWithoutConstructor();
	$image_validation_method = new \ReflectionMethod(Block_Tree_Service::class, 'validate_core_image_markup');
	$image_validation_method->setAccessible(true);
	$invalid_dimension_block = $image_block;
	$invalid_dimension_block['innerHTML'] = str_replace(' class="wp-image-42"', ' width="1024" class="wp-image-42"', $invalid_dimension_block['innerHTML']);
	$invalid_dimension_errors = array();
	$image_validation_method->invokeArgs($tree_service, array($invalid_dimension_block, &$invalid_dimension_errors));
	assert_same(
		'core_image_dimension_attribute_mismatch',
		$invalid_dimension_errors[0]['code'] ?? null,
		'The Composer must reject Image HTML dimensions missing from block attributes.'
	);

	$invalid_class_block = $image_block;
	$invalid_class_block['attrs']['className'] = 'wps-flow-modal-open--media-modal';
	$invalid_class_errors = array();
	$image_validation_method->invokeArgs($tree_service, array($invalid_class_block, &$invalid_class_errors));
	assert_same(
		'core_image_custom_class_mismatch',
		$invalid_class_errors[0]['code'] ?? null,
		'The Composer must reject trigger classes present only in block attributes.'
	);

	echo "media-image-contract: ok\n";
}
