<?php

declare(strict_types=1);

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	require_once dirname(__DIR__) . '/src/Execution/Abilities.php';

	$abilities = (new \ReflectionClass(Abilities::class))->newInstanceWithoutConstructor();
	$schema    = $abilities->remote_media_schema();
	if ( array('source_url', 'idempotency_key') !== ($schema['required'] ?? null) ) {
		throw new RuntimeException('Remote media ingestion must require a source URL and stable idempotency key.');
	}
	$assignment = $schema['properties']['featured_for'] ?? array();
	if ( array('post_id', 'page_type', 'expected_modified_gmt', 'expected_revision') !== ($assignment['required'] ?? null) ) {
		throw new RuntimeException('Featured-image assignment must require the complete optimistic-concurrency contract.');
	}
	$existing_assignment = $abilities->featured_image_assignment_schema();
	if (
		array('post_id', 'page_type', 'attachment_id', 'expected_modified_gmt', 'expected_revision')
		!== ($existing_assignment['required'] ?? null)
	) {
		throw new RuntimeException('Existing Media Library featured-image assignment must require the complete optimistic-concurrency contract.');
	}

	$ingestor_source = file_get_contents(dirname(__DIR__) . '/src/Execution/Remote_Media_Ingestor.php');
	$draft_source    = file_get_contents(dirname(__DIR__) . '/src/Execution/Draft_Service.php');
	if (! is_string($ingestor_source) || ! is_string($draft_source)) {
		throw new RuntimeException('Remote media execution sources must be readable.');
	}
	foreach (array(
		'Activation::CAP_INGEST_MEDIA',
		'get_remote_media_ingest_policy',
		'wp_safe_remote_get',
		"'reject_unsafe_urls'",
		"'limit_response_size'",
		'wp_check_filetype_and_ext',
		'SOURCE_SHA256_META',
		'IDEMPOTENCY_META',
	) as $required_source) {
		if (! str_contains($ingestor_source, $required_source)) {
			throw new RuntimeException('Remote media ingestion is missing a required safety or idempotency boundary: ' . $required_source);
		}
	}
	if (str_contains($ingestor_source, 'download_url(')) {
		throw new RuntimeException('Remote media ingestion must retain the explicit bounded safe HTTP request.');
	}
	foreach (array('get_owned_draft', 'begin_locked_update', 'set_post_thumbnail', "'post_status' => 'draft'") as $required_source) {
		if (! str_contains($draft_source, $required_source)) {
			throw new RuntimeException('Featured-image assignment is missing its owned-draft boundary: ' . $required_source);
		}
	}

	echo "remote-media-contract: ok\n";
}
