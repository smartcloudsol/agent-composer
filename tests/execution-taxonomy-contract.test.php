<?php

declare(strict_types=1);

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	require_once dirname(__DIR__) . '/src/Execution/Abilities.php';

	function taxonomy_assert(bool $condition, string $message): void {
		if (! $condition) {
			throw new RuntimeException($message);
		}
	}

	function taxonomy_same(mixed $expected, mixed $actual, string $message): void {
		if ($expected !== $actual) {
			throw new RuntimeException($message . sprintf(' Expected %s, got %s.', var_export($expected, true), var_export($actual, true)));
		}
	}

	$abilities = (new \ReflectionClass(Abilities::class))->newInstanceWithoutConstructor();

	$search = $abilities->taxonomy_search_schema();
	taxonomy_same(array('page_type', 'taxonomy'), $search['required'] ?? null, 'Term search must be scoped by Blueprint and taxonomy.');
	taxonomy_assert(false === ($search['additionalProperties'] ?? true), 'Term search must reject unknown inputs.');

	$create = $abilities->taxonomy_create_schema();
	taxonomy_same(
		array('page_type', 'content_language', 'taxonomy', 'name', 'slug', 'description', 'confirm_create'),
		$create['required'] ?? null,
		'Term creation must require public copy, language, taxonomy scope, and confirmation.'
	);
	taxonomy_assert(isset($create['properties']['parent_slug']), 'Hierarchical parents must be selected by durable slug.');
	taxonomy_assert(! isset($create['properties']['parent_id']), 'The public creation contract must not accept an unreviewed parent ID.');
	taxonomy_same(array(true), $create['properties']['confirm_create']['enum'] ?? null, 'Term creation confirmation must be literal true.');

	$assign = $abilities->taxonomy_assignment_schema();
	taxonomy_same(
		array('post_id', 'page_type', 'taxonomy', 'term_ids', 'mode', 'expected_modified_gmt', 'expected_revision', 'confirm_assignment'),
		$assign['required'] ?? null,
		'Term assignment must require ownership scope, policy mode, both concurrency tokens, and confirmation.'
	);
	taxonomy_same(true, $assign['properties']['term_ids']['uniqueItems'] ?? null, 'Term IDs must be unique.');
	taxonomy_same(array('replace', 'append'), $assign['properties']['mode']['enum'] ?? null, 'Assignment mode must remain bounded.');

	$inspect = $abilities->taxonomy_inspection_schema();
	taxonomy_same(array('post_id', 'page_type'), $inspect['required'] ?? null, 'Taxonomy inspection must identify an assigned Blueprint draft.');

	$contract_output = $abilities->taxonomy_contract_output_schema();
	taxonomy_same(array(false), $contract_output['properties']['term_edit_supported']['enum'] ?? null, 'The contract must prohibit term edits.');
	taxonomy_same(array(false), $contract_output['properties']['term_delete_supported']['enum'] ?? null, 'The contract must prohibit term deletion.');

	$service_source = file_get_contents(dirname(__DIR__) . '/src/Execution/Taxonomy_Term_Service.php');
	$draft_source   = file_get_contents(dirname(__DIR__) . '/src/Execution/Draft_Service.php');
	$runtime_source = file_get_contents(dirname(__DIR__) . '/src/Application/Execution/ExecutionRuntime.php');
	$role_source    = file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/Activation.php');
	foreach (array($service_source, $draft_source, $runtime_source, $role_source) as $source) {
		taxonomy_assert(is_string($source), 'Every taxonomy execution source must be readable.');
	}

	foreach (array(
		'get_content_taxonomy_access',
		'wp_insert_term',
		'creation_parent_policy',
		'creation_parent_slugs',
		'assignment_mode',
		'CAP_CREATE_TERMS',
		'CAP_ASSIGN_TERMS',
		'get_owned_draft',
	) as $required) {
		taxonomy_assert(str_contains($service_source, $required), 'Taxonomy service is missing required policy or ownership boundary: ' . $required);
	}
	taxonomy_assert(! str_contains($service_source, 'wp_update_term'), 'Composer must never edit an existing taxonomy term.');
	taxonomy_assert(! str_contains($service_source, 'wp_delete_term'), 'Composer must never delete a taxonomy term.');

	foreach (array('begin_locked_update', 'wp_set_object_terms', 'expected_modified_gmt', 'expected_revision', 'clean_object_term_cache', "'post_status' => 'draft'") as $required) {
		taxonomy_assert(str_contains($draft_source, $required), 'Taxonomy assignment is missing a draft concurrency boundary: ' . $required);
	}
	taxonomy_assert(str_contains($runtime_source, 'new Taxonomy_Term_Service'), 'The runtime must construct the taxonomy service.');
	taxonomy_assert(str_contains($runtime_source, '$taxonomy_terms'), 'The runtime must inject the taxonomy service into abilities.');
	taxonomy_assert(str_contains($role_source, "ROLE_SCHEMA_VERSION = '4'"), 'Adding governed capabilities must advance the role schema.');
	foreach (array('CAP_ASSIGN_TERMS', 'CAP_CREATE_TERMS') as $capability) {
		taxonomy_assert(str_contains($role_source, $capability), 'The dedicated role is missing a governed taxonomy capability: ' . $capability);
	}
	foreach (array('manage_categories', 'manage_terms', 'edit_terms', 'delete_terms') as $native_capability) {
		taxonomy_assert(! str_contains($role_source, "'" . $native_capability . "' => true"), 'The agent role must not receive broad native term management: ' . $native_capability);
	}

	echo "taxonomy-contract: ok\n";
}
