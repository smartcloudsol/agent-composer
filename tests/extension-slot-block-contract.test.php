<?php

declare(strict_types=1);

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$root = dirname( __DIR__ );
define( 'SMARTCLOUD_COMPOSER_DIR', $root . '/' );
define( 'SMARTCLOUD_COMPOSER_URL', 'https://example.test/wp-content/plugins/smartcloud-agent-composer/' );
define( 'SMARTCLOUD_COMPOSER_VERSION', 'test' );
$GLOBALS['composer_block_registration'] = array();
function wp_register_script( string $handle, string $source, array $dependencies, string $version, bool $footer ): bool {
	$GLOBALS['composer_block_registration']['script'] = compact( 'handle', 'source', 'dependencies', 'version', 'footer' );
	return true;
}
function wp_register_style( string $handle, string $source, array $dependencies, string $version ): bool {
	$GLOBALS['composer_block_registration']['style'] = compact( 'handle', 'source', 'dependencies', 'version' );
	return true;
}
function wp_set_script_translations( string $handle, string $domain, string $path ): bool {
	$GLOBALS['composer_block_registration']['translations'] = compact( 'handle', 'domain', 'path' );
	return true;
}
function register_block_type( string $path ): object {
	$GLOBALS['composer_block_registration']['block'] = $path;
	return (object) array( 'name' => 'smartcloud-agent-composer/extension-slot' );
}
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}
require_once $root . '/src/Infrastructure/WordPress/BlockRegistry.php';
require_once $root . '/src/Execution/Block_Tree_Service.php';
\SmartCloud\AgentComposer\Infrastructure\WordPress\BlockRegistry::register();

$metadata = json_decode( (string) file_get_contents( $root . '/blocks/src/extension-slot/block.json' ), true );
$built_metadata = json_decode( (string) file_get_contents( $root . '/blocks/dist/extension-slot/block.json' ), true );
$assert( is_array( $metadata ), 'The extension-slot source metadata must be valid JSON.' );
$assert( $metadata === $built_metadata, 'The packaged extension-slot metadata must match its reviewed source.' );
$assert( 'smartcloud-agent-composer/extension-slot' === ( $metadata['name'] ?? '' ), 'The extension slot must use its contract block name.' );
$assert( false === ( $metadata['supports']['inserter'] ?? true ), 'Editors must not insert structural slot definitions outside a Blueprint.' );
$assert( false === ( $metadata['supports']['html'] ?? true ), 'The slot block must not expose raw HTML editing.' );
foreach ( array( 'slotId', 'allowedBlocks', 'allowedPatterns', 'patternOccurrences', 'minBlocks', 'maxBlocks' ) as $attribute ) {
	$assert( isset( $metadata['attributes'][ $attribute ] ), 'The slot block is missing its governed attribute: ' . $attribute );
}

$tree_service = ( new ReflectionClass( \SmartCloud\AgentComposer\Execution\Block_Tree_Service::class ) )->newInstanceWithoutConstructor();
$validate_attributes = new ReflectionMethod( \SmartCloud\AgentComposer\Execution\Block_Tree_Service::class, 'validate_attributes' );
$validation_errors = array();
$validation_args = array(
	'smartcloud-agent-composer/extension-slot',
	array( 'templateLock' => false ),
	(array) ( $metadata['attributes'] ?? array() ),
	&$validation_errors,
);
$validate_attributes->invokeArgs( $tree_service, $validation_args );
$assert( array() === $validation_errors, 'The editor projector templateLock attribute must pass the shared block validator.' );

$identity_source = (string) file_get_contents( $root . '/blocks/src/extension-slot/identity.ts' );
$edit_source = (string) file_get_contents( $root . '/blocks/src/extension-slot/edit.tsx' );
$registry_source = (string) file_get_contents( $root . '/src/Infrastructure/WordPress/BlockRegistry.php' );
$catalog_source = (string) file_get_contents( $root . '/src/Execution/Block_Catalog.php' );
$plugin_source = (string) file_get_contents( $root . '/src/Plugin.php' );
$assembler_source = (string) file_get_contents( dirname( $root ) . '/wpsuite-plugins/scripts/assemble.mjs' );
foreach ( array( 'userBlockId', 'crypto.randomUUID', 'getRandomValues', 'USER_BLOCK_ID' ) as $marker ) {
	$assert( str_contains( $identity_source, $marker ), 'Stable user-block identity is missing: ' . $marker );
}
foreach ( array( 'allowedBlocks', 'allowedPatterns', 'editorAllowedBlocks', 'core/block', 'minBlocks', 'maxBlocks', 'updateBlockAttributes', 'ownership: "USER"', 'slotId', 'userBlockId', 'canInsertBlockType', 'insertBlock(', 'createBlock(blockName)', '+ Add block', '%1$d of %2$d blocks' ) as $marker ) {
	$assert( str_contains( $edit_source, $marker ), 'The extension-slot editor boundary is missing: ' . $marker );
}
$assert( str_contains( $registry_source, "register_block_type( \$extension_slot )" ), 'WordPress must register the packaged extension-slot metadata.' );
$assert( 'smartcloud-agent-composer-blocks-editor-script' === ( $GLOBALS['composer_block_registration']['script']['handle'] ?? '' ), 'The block registry must register the built editor script.' );
$assert( in_array( 'wp-block-editor', $GLOBALS['composer_block_registration']['script']['dependencies'] ?? array(), true ), 'The generated WordPress dependency manifest must drive script registration.' );
$assert( $root . '/blocks/dist/extension-slot' === ( $GLOBALS['composer_block_registration']['block'] ?? '' ), 'The source runtime must register the built extension-slot directory.' );
$assert( str_contains( $plugin_source, "BlockRegistry::class, 'register'" ), 'Plugin boot must register governed blocks on init.' );
$assert( str_contains( $catalog_source, 'is_contract_internal_block' ), 'The extension slot must be authorized by its active Structure Contract without a duplicate site-specific plugin contract.' );
$assert( str_contains( $assembler_source, 'modules: ["admin", "blocks"]' ), 'The canonical plugin assembler must stage the Composer blocks module.' );
$assert( str_contains( $assembler_source, '"blocks/extension-slot/block.json"' ), 'The canonical package verifier must require the extension-slot metadata.' );
foreach ( array( 'editor.js', 'editor.asset.php', 'editor.css' ) as $asset ) {
	$assert( is_readable( $root . '/blocks/dist/' . $asset ), 'The built block editor asset is missing: ' . $asset );
}

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "extension-slot-block-contract: ok\n";
