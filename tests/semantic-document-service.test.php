<?php

declare(strict_types=1);

use SmartCloud\AgentComposer\Execution\Semantic_Document_Service;
use SmartCloud\AgentComposer\Execution\Block_Tree_Service;

function wp_kses_post( string $value ): string {
	return $value;
}

function sanitize_key( string $value ): string {
	return strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $value ) );
}

function esc_url( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_attr( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function wp_generate_uuid4(): string {
	return '123e4567-e89b-12d3-a456-426614174999';
}

require_once dirname( __DIR__ ) . '/src/Execution/Semantic_Document_Service.php';
require_once dirname( __DIR__ ) . '/src/Execution/Block_Tree_Service.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$node = static function ( string $id, string $block, string $ownership, string $mode, ?string $parent = null, array $extra = array() ): array {
	return array_merge(
		array(
			'id'                  => $id,
			'block'               => $block,
			'ownership'           => $ownership,
			'mode'                => $mode,
			'parent'              => $parent,
			'editable_attributes' => array(),
			'editable_content'    => false,
		),
		$extra
	);
};
$block = static function ( string $name, ?string $node_id, array $attrs = array(), string $html = '', array $children = array() ): array {
	if ( null !== $node_id ) {
		$attrs['metadata']['wpsuiteAgentComposer']['nodeId'] = $node_id;
	}
	return array(
		'blockName'    => $name,
		'attrs'        => $attrs,
		'innerHTML'    => $html,
		'innerContent' => array( $html ),
		'innerBlocks'  => $children,
	);
};

$contract = array(
	'id'      => 'solution-editor',
	'version' => 3,
	'nodes'   => array(
		$node( 'hero', 'core/group', 'BLUEPRINT', 'structure' ),
		$node(
			'hero.title',
			'core/heading',
			'INSTANCE_CONTENT',
			'content',
			'hero',
			array( 'editable_attributes' => array( 'level' ), 'editable_content' => true )
		),
		$node(
			'hero.image',
			'core/image',
			'INSTANCE_CONTENT',
			'content',
			'hero',
			array( 'editable_attributes' => array( 'id', 'sizeSlug' ), 'editable_content' => true )
		),
		$node(
			'additional-content',
			'smartcloud-agent-composer/extension-slot',
			'BLUEPRINT',
			'slot',
			null,
			array( 'allowed_blocks' => array( 'core/paragraph' ), 'min_blocks' => 0, 'max_blocks' => 3 )
		),
	),
);

$user = $block(
	'core/paragraph',
	null,
	array(
		'metadata' => array(
			'name' => 'Visible user label',
			'wpsuiteAgentComposer' => array(
				'userBlockId' => 'user-12345678',
				'slotId'      => 'additional-content',
			),
		),
		'lock' => array( 'move' => false ),
	),
	'<p>Additional detail</p>'
);
$title = $block(
	'core/heading',
	'hero.title',
	array( 'level' => 1, 'className' => 'protected-presentation' ),
	'<h1>Semantic <strong>title</strong></h1>'
);
$image = $block(
	'core/image',
	'hero.image',
	array( 'id' => 42, 'sizeSlug' => 'large', 'linkDestination' => 'media' ),
	'<figure class="wp-block-image size-large custom-figure"><a href="https://example.test/old-full.jpg"><img src="https://example.test/old-large.jpg" alt="Old alt" class="custom-image wp-image-42" title="Old title" style="object-fit:cover" srcset="old" sizes="old"/></a><figcaption class="wp-element-caption">Keep <em>caption</em></figcaption></figure>'
);
$hero = $block( 'core/group', 'hero', array(), '<div></div>', array( $title, $image ) );
$slot = $block( 'smartcloud-agent-composer/extension-slot', 'additional-content', array(), '<div></div>', array( $user ) );

$reflection = new ReflectionClass( Semantic_Document_Service::class );
/** @var Semantic_Document_Service $service */
$service    = $reflection->newInstanceWithoutConstructor();
$document   = $service->project_document( $contract, array( $hero, $slot ) );
$by_id      = array_column( $document['nodes'], null, 'id' );

$assert( array( 'hero.title', 'hero.image' ) === $document['field_ids'], 'The semantic projection must list stable instance-content field IDs.' );
$assert( array( 'hero.image' ) === $document['media_field_ids'], 'The semantic projection must identify attachment-ID-addressable media fields.' );
$assert( array( 'additional-content' ) === $document['slot_ids'], 'The semantic projection must list stable extension-slot IDs.' );
$assert( true === $by_id['hero.title']['present'], 'The semantic field must resolve without exposing its physical Gutenberg path.' );
$assert( array( 'level' => 1 ) === $by_id['hero.title']['values'], 'Only contract-declared editable attributes may be exposed.' );
$assert( 'Semantic <strong>title</strong>' === $by_id['hero.title']['content'], 'Editable rich text must be returned without its physical block wrapper.' );
$assert( ! isset( $by_id['hero.title']['path'] ), 'Physical Gutenberg indexes must not be part of the semantic document surface.' );
$assert( 42 === ( $by_id['hero.image']['media']['attachment_id'] ?? 0 ), 'A semantic image field must expose its attachment ID instead of serialized block markup.' );
$assert( 'Old alt' === ( $by_id['hero.image']['media']['alt'] ?? '' ), 'A semantic image field must expose its current alt text.' );
$assert( 'Keep <em>caption</em>' === ( $by_id['hero.image']['media']['caption'] ?? '' ), 'A semantic image field must expose its caption as rich text.' );
$assert( ! isset( $by_id['hero.image']['content'] ), 'A semantic image field must not expose its Figure markup as generic rich text.' );

$slot_block = $by_id['additional-content']['blocks'][0] ?? array();
$assert( 'user-12345678' === ( $slot_block['user_block_id'] ?? '' ), 'Slot children must be addressable by their stable user-owned identity.' );
$assert( 'Additional detail' === ( $slot_block['content'] ?? '' ), 'Slot block content must be readable without serialized Gutenberg comments.' );
$assert( ! isset( $slot_block['attributes']['lock'] ), 'Native editor locks must not leak into the semantic user-block payload.' );
$assert( ! isset( $slot_block['attributes']['metadata']['wpsuiteAgentComposer'] ), 'Composer control metadata must not leak into editable user attributes.' );
$assert( 'Visible user label' === ( $slot_block['attributes']['metadata']['name'] ?? '' ), 'Non-Composer user metadata must be preserved.' );

$replace_content = new ReflectionMethod( Semantic_Document_Service::class, 'replace_block_content' );
$replace_content->setAccessible( true );
$updated_title = $replace_content->invoke( $service, $title, 'Updated <em>title</em>' );
$assert( '<h1>Updated <em>title</em></h1>' === ( $updated_title['innerHTML'] ?? '' ), 'A semantic rich-text update must preserve the Gutenberg block shell.' );
$assert( array( '<h1>Updated <em>title</em></h1>' ) === ( $updated_title['innerContent'] ?? null ), 'A semantic rich-text update must keep innerContent synchronized.' );

$find_occurrences = new ReflectionMethod( Semantic_Document_Service::class, 'find_node_occurrences' );
$find_occurrences->setAccessible( true );
$definitions = array_column( $contract['nodes'], null, 'id' );
$occurrences = $find_occurrences->invoke( $service, array( $hero, $slot ), $definitions, 'hero.title' );
$assert( array( 0, 0 ) === ( $occurrences[0]['path'] ?? null ), 'A semantic mutation must resolve nested Gutenberg indexes internally without exposing them to the caller.' );

$replace_image = new ReflectionMethod( Semantic_Document_Service::class, 'replace_image_block' );
$replace_image->setAccessible( true );
$updated_image = $replace_image->invoke(
	$service,
	$image,
	99,
	'thumbnail',
	'https://example.test/new-thumbnail.jpg',
	'https://example.test/new-full.jpg',
	'New & alt',
	'New title'
);
$updated_image_html = (string) ( $updated_image['innerHTML'] ?? '' );
$assert( 99 === ( $updated_image['attrs']['id'] ?? 0 ), 'Media replacement must synchronize the core/image attachment ID.' );
$assert( 'thumbnail' === ( $updated_image['attrs']['sizeSlug'] ?? '' ), 'Media replacement must synchronize the requested image size.' );
$assert( str_contains( $updated_image_html, 'size-thumbnail' ) && ! str_contains( $updated_image_html, 'size-large' ), 'Media replacement must synchronize the Figure size class.' );
$assert( str_contains( $updated_image_html, 'custom-figure' ) && str_contains( $updated_image_html, 'custom-image' ), 'Media replacement must preserve presentation classes.' );
$assert( str_contains( $updated_image_html, 'href="https://example.test/new-full.jpg"' ), 'Media links must target the full attachment URL.' );
$assert( str_contains( $updated_image_html, 'src="https://example.test/new-thumbnail.jpg"' ), 'The Image source must use the selected registered size.' );
$assert( str_contains( $updated_image_html, 'alt="New &amp; alt"' ) && str_contains( $updated_image_html, 'wp-image-99' ), 'Media replacement must synchronize escaped accessibility text and the attachment class.' );
$assert( str_contains( $updated_image_html, 'style="object-fit:cover"' ), 'Media replacement must preserve existing Image presentation attributes.' );
$assert( ! str_contains( $updated_image_html, 'srcset=' ) && ! str_contains( $updated_image_html, 'sizes=' ), 'Media replacement must remove stale responsive candidates from the previous attachment.' );
$assert( str_contains( $updated_image_html, '<figcaption class="wp-element-caption">Keep <em>caption</em></figcaption>' ), 'Media replacement must preserve the authored caption.' );
$assert( array( $updated_image_html ) === ( $updated_image['innerContent'] ?? null ), 'Media replacement must keep innerContent synchronized.' );
$tree_service = ( new ReflectionClass( Block_Tree_Service::class ) )->newInstanceWithoutConstructor();
$validate_image = new ReflectionMethod( Block_Tree_Service::class, 'validate_core_image_markup' );
$validate_image->setAccessible( true );
$image_errors = array();
$validate_image->invokeArgs( $tree_service, array( $updated_image, &$image_errors ) );
$assert( array() === $image_errors, 'The semantic media result must satisfy the canonical core/image markup validator.' );

$mark_user = new ReflectionMethod( Semantic_Document_Service::class, 'mark_user_tree' );
$mark_user->setAccessible( true );
$marked = $mark_user->invoke( $service, $block( 'core/paragraph', null, array(), '<p>New</p>' ), $contract, 'additional-content' );
$assert( 'user-123e4567-e89b-12d3-a456-426614174999' === ( $marked['attrs']['metadata']['wpsuiteAgentComposer']['userBlockId'] ?? '' ), 'Inserted slot blocks must receive a stable user-owned identity.' );
$assert( 'USER' === ( $marked['attrs']['metadata']['wpsuiteAgentComposer']['ownership'] ?? '' ), 'Inserted slot blocks must be explicitly user-owned.' );
$assert( 'additional-content' === ( $marked['attrs']['metadata']['wpsuiteAgentComposer']['slotId'] ?? '' ), 'Inserted slot identities must remain scoped to their extension slot.' );

$slot_inner_content = new ReflectionMethod( Semantic_Document_Service::class, 'slot_inner_content' );
$slot_inner_content->setAccessible( true );
$rebuilt_content = $slot_inner_content->invoke( $service, array( '<div>', null, '</div>' ), '<div></div>', 3 );
$assert( array( '<div>', null, null, null, '</div>' ) === $rebuilt_content, 'Slot insertion and reordering must keep native innerContent placeholders synchronized.' );

$service_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Semantic_Document_Service.php' );
$abilities      = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Abilities.php' );
$runtime        = (string) file_get_contents( dirname( __DIR__ ) . '/src/Application/Execution/ExecutionRuntime.php' );
$assert( str_contains( $service_source, "'raw_post_content_included' => false" ), 'The document read surface must explicitly omit full post_content.' );
$assert( str_contains( $abilities, "'get-contract'" ) && str_contains( $abilities, "'get-document'" ), 'Both semantic read abilities must be registered.' );
$assert( str_contains( $abilities, "'set-field'" ), 'The semantic field mutation ability must be registered.' );
$assert( str_contains( $abilities, "'replace-media'" ), 'The semantic media replacement ability must be registered.' );
$assert( str_contains( $abilities, "'insert-slot-block'" ) && str_contains( $abilities, "'update-slot-block'" ) && str_contains( $abilities, "'move-slot-block'" ) && str_contains( $abilities, "'remove-slot-block'" ), 'The complete identity-addressed semantic slot mutation surface must be registered.' );
$assert( str_contains( $abilities, "'validate-proposal'" ), 'The semantic proposal validation ability must be registered.' );
$assert( str_contains( $service_source, '$this->drafts->insert_or_update_blocks' ), 'Semantic field mutation must reuse the governed draft mutation boundary.' );
$assert( str_contains( $runtime, 'new Semantic_Document_Service' ), 'The execution runtime must wire the semantic document service.' );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "semantic-document-service: ok\n";
