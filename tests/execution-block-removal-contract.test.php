<?php

declare(strict_types=1);

use SmartCloud\AgentComposer\Execution\Abilities;
use SmartCloud\AgentComposer\Execution\Block_Tree_Service;
use SmartCloud\AgentComposer\Execution\Execution_Exception;

require_once dirname(__DIR__) . '/src/Execution/Execution_Exception.php';
require_once dirname(__DIR__) . '/src/Execution/Block_Tree_Service.php';
require_once dirname(__DIR__) . '/src/Execution/Abilities.php';

if (! function_exists('parse_blocks')) {
	function parse_blocks(string $content): array { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.contentFound
		return array();
	}
}

function assert_same(mixed $expected, mixed $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . sprintf(' Expected %s, got %s.', var_export($expected, true), var_export($actual, true))
		);
	}
}

$abilities = (new ReflectionClass(Abilities::class))->newInstanceWithoutConstructor();
$schema_method = new ReflectionMethod(Abilities::class, 'block_update_schema');
$schema_method->setAccessible(true);
$schema = $schema_method->invoke($abilities);

assert_same(0, $schema['properties']['blocks']['minItems'] ?? null, 'The Ability schema must accept an empty block list.');

$service = (new ReflectionClass(Block_Tree_Service::class))->newInstanceWithoutConstructor();

$preformatted = array(
	array(
		'blockName'    => 'core/preformatted',
		'attrs'        => array('className' => 'wps-product-diagram'),
		'innerBlocks'  => array(),
		'innerHTML'    => '<pre class="wp-block-preformatted wps-product-diagram">A' . "\n" . 'B</pre>',
		'innerContent' => array('<pre class="wp-block-preformatted wps-product-diagram">A' . "\n" . 'B</pre>'),
	),
);
$normalized = $service->normalize_blocks($preformatted);
assert_same(
	'core/preformatted',
	$normalized[0]['blockName'] ?? null,
	'A canonical replacement block must retain its valid blockName.'
);

$legacy_html = '<div class="wp-block-group alignfull wps-product-page"><p>Legacy comparison.</p></div>';
$parsed = array(
	array(
		'blockName'    => null,
		'attrs'        => array(),
		'innerBlocks'  => array(),
		'innerHTML'    => $legacy_html,
		'innerContent' => array($legacy_html),
	),
);
$canonicalize_method = new ReflectionMethod(Block_Tree_Service::class, 'canonicalize_parsed_blocks');
$canonicalize_method->setAccessible(true);
$canonicalized = $canonicalize_method->invoke($service, $parsed);
assert_same(
	'core/freeform',
	$canonicalized[0]['blockName'] ?? null,
	'Existing delimiter-free Classic HTML must be canonicalized before a targeted update.'
);
assert_same(
	$legacy_html,
	$canonicalized[0]['innerHTML'] ?? null,
	'Canonicalizing legacy Classic HTML must preserve its saved markup.'
);
$service->normalize_blocks($canonicalized);

try {
	$service->apply_to_content('', array(), 'append', array(), '');
	throw new RuntimeException('An empty append operation must fail.');
} catch (Execution_Exception $error) {
	assert_same(
		'empty_block_replacement_required',
		$error->get_execution_code(),
		'Empty non-replace operations must use the dedicated validation error.'
	);
}

$nodes = array(
	array(
		'blockName'    => 'core/paragraph',
		'attrs'        => array(),
		'innerBlocks'  => array(),
		'innerHTML'    => '<p>Remove me.</p>',
		'innerContent' => array('<p>Remove me.</p>'),
	),
);
$apply_method = new ReflectionMethod(Block_Tree_Service::class, 'apply_at_path');
$apply_method->setAccessible(true);
$arguments = array(&$nodes, array(), 'replace', array(0));
$apply_method->invokeArgs($service, $arguments);

assert_same(array(), $nodes, 'Replacing a targeted top-level block with an empty list must remove it.');

echo "block-removal-contract: ok\n";
