<?php

namespace SmartCloud\AgentComposer\Infrastructure\WordPress;

/** Register the packaged contract-governed Gutenberg blocks and editor assets. */
final class BlockRegistry {
	private const EDITOR_SCRIPT = 'smartcloud-agent-composer-blocks-editor-script';
	private const EDITOR_STYLE  = 'smartcloud-agent-composer-blocks-editor-style';

	public static function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$blocks_dir = SMARTCLOUD_COMPOSER_DIR . 'blocks/';
		$blocks_url = SMARTCLOUD_COMPOSER_URL . 'blocks/';
		if ( ! is_readable( $blocks_dir . 'editor.js' ) && is_readable( $blocks_dir . 'dist/editor.js' ) ) {
			$blocks_dir .= 'dist/';
			$blocks_url .= 'dist/';
		}
		$asset_file = $blocks_dir . 'editor.asset.php';
		$asset      = is_readable( $asset_file ) ? require $asset_file : array();
		$script     = $blocks_dir . 'editor.js';
		if ( ! is_readable( $script ) ) {
			return;
		}

		wp_register_script(
			self::EDITOR_SCRIPT,
			$blocks_url . 'editor.js',
			array_values( array_unique( (array) ( $asset['dependencies'] ?? array() ) ) ),
			(string) ( $asset['version'] ?? SMARTCLOUD_COMPOSER_VERSION ),
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::EDITOR_SCRIPT, 'smartcloud-agent-composer', SMARTCLOUD_COMPOSER_DIR . 'languages' );
		}
		if ( is_readable( $blocks_dir . 'editor.css' ) ) {
			wp_register_style(
				self::EDITOR_STYLE,
				$blocks_url . 'editor.css',
				array(),
				(string) ( $asset['version'] ?? SMARTCLOUD_COMPOSER_VERSION )
			);
		}

		$extension_slot = $blocks_dir . 'extension-slot';
		if ( is_readable( $extension_slot . '/block.json' ) ) {
			register_block_type( $extension_slot );
		}
	}

	private function __construct() {}
}
