<?php

declare(strict_types=1);

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	require_once dirname(__DIR__) . '/src/Execution/Abilities.php';

	$abilities = (new \ReflectionClass(Abilities::class))->newInstanceWithoutConstructor();
	$schema = $abilities->publisher_media_upload_schema();
	$required = $schema['required'] ?? array();
	foreach (array('mime_type', 'slug', 'title', 'alt_mode', 'alt_text', 'idempotency_key', 'confirm_publication', 'confirm_rights') as $field) {
		if (! in_array($field, $required, true)) {
			throw new RuntimeException('Publisher media schema is missing required field: ' . $field);
		}
	}
	if (2 !== count($schema['oneOf'] ?? array())) {
		throw new RuntimeException('Publisher media upload must accept exactly one of base64 bytes or a safe HTTPS source.');
	}
	if ('^[a-z0-9]+(?:-[a-z0-9]+)*$' !== ($schema['properties']['slug']['pattern'] ?? '')) {
		throw new RuntimeException('Publisher media filename slugs must use the semantic lowercase-hyphen contract.');
	}
	if (array(true) !== ($schema['properties']['confirm_publication']['enum'] ?? null) || array(true) !== ($schema['properties']['confirm_rights']['enum'] ?? null)) {
		throw new RuntimeException('Publisher media publication and rights acknowledgements must be explicit true-only confirmations.');
	}

	$uploader = file_get_contents(dirname(__DIR__) . '/src/Execution/Publisher_Media_Uploader.php');
	$abilities_source = file_get_contents(dirname(__DIR__) . '/src/Execution/Abilities.php');
	$access = file_get_contents(dirname(__DIR__) . '/src/Security/McpAccessGuard.php');
	$activation = file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/Activation.php');
	if (! is_string($uploader) || ! is_string($abilities_source) || ! is_string($access) || ! is_string($activation)) {
		throw new RuntimeException('Publisher media contract sources must be readable.');
	}
	foreach (array(
		"'publisher' !== \$actor->role()",
		"'cognito' !== \$actor->source()",
		'Activation::CAP_PUBLISH_MEDIA',
		'confirm_publication',
		'confirm_rights',
		'base64_decode',
		'wp_safe_remote_get',
		"'redirection'         => 0",
		"'limit_response_size'",
		'wp_check_filetype_and_ext',
		'wp_getimagesize',
		'media_handle_sideload',
		"'post_name'",
		"'_wp_attachment_image_alt'",
		'IDEMPOTENCY_META',
		'acquire_lock',
		'add_option',
		"\$actor->principal_id() . \"\\0\" . \$idempotency_key",
		'generated-image',
	) as $boundary) {
		if (! str_contains($uploader, $boundary)) {
			throw new RuntimeException('Publisher media upload is missing required boundary: ' . $boundary);
		}
	}
	if (! str_contains($abilities_source, "unset( \$audit_input['content_base64'], \$audit_input['source_url'] )")) {
		throw new RuntimeException('Raw media bytes and source URLs must be removed before operation audit hashing.');
	}
	if (! str_contains($access, "'publish_media'   => 'composer.publish.request'")) {
		throw new RuntimeException('Publisher media upload must reuse the established resource-bound publication scope.');
	}
	if (! str_contains($activation, 'self::CAP_PUBLISH_MEDIA') || ! str_contains($activation, "ROLE_SCHEMA_VERSION = '8'")) {
		throw new RuntimeException('Publisher media publication requires a migrated narrow WordPress capability.');
	}

	echo "publisher-media-upload-contract: ok\n";
}
