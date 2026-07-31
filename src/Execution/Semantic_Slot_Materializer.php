<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

/**
 * Materialize theme-declared semantic slots without exposing raw placeholders
 * in patterns inserted manually through the Site Editor.
 */
final class Semantic_Slot_Materializer {
	private ?array $manifest = null;

	public function slots_for_pattern( string $pattern ): array {
		$pattern = strtolower( trim( $pattern ) );
		foreach ( (array) ( $this->manifest()['components'] ?? array() ) as $component ) {
			if (
				is_array( $component )
				&& in_array( $pattern, (array) ( $component['patterns'] ?? array() ), true )
			) {
				return $this->normalize_slots( $component['slots'] ?? array() );
			}
		}
		return array();
	}

	public function materialize( string $pattern, string $content, array $fields ): string {
		$slots = $this->slots_for_pattern( $pattern );
		if ( empty( $slots ) ) {
			return $content;
		}

		$blocks = parse_blocks( trim( $content ) );
		$ordered = $slots;
		uasort(
			$ordered,
			static function ( array $left, array $right ): int {
				$class_order = strcmp( $left['class'], $right['class'] );
				return 0 !== $class_order
					? $class_order
					: $right['target']['occurrence'] <=> $left['target']['occurrence'];
			}
		);

		foreach ( $ordered as $slot_id => $slot ) {
			$provided = array_key_exists( $slot_id, $fields ) && ! $this->is_empty_value( $fields[ $slot_id ] );
			if ( ! $provided ) {
				if ( 'reject' === $slot['on_missing'] ) {
					throw new Execution_Exception( 'missing_semantic_slot', 'A required semantic pattern slot was not supplied: ' . $slot_id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ability error, not HTML.
				}
				if ( 'remove' === $slot['on_missing'] ) {
					$blocks = $this->transform_target( $blocks, $slot, null, true );
				}
				continue;
			}

			$blocks = $this->transform_target( $blocks, $slot, $fields[ $slot_id ], false );
		}

		$serialized = serialize_blocks( $blocks );
		$round_trip = serialize_blocks( parse_blocks( $serialized ) );
		if ( ! hash_equals( hash( 'sha256', $serialized ), hash( 'sha256', $round_trip ) ) ) {
			throw new Execution_Exception( 'semantic_slot_round_trip_failed', 'Semantic slot materialization did not survive a Gutenberg parse and serialize round trip.' );
		}
		$this->assert_no_registered_fallbacks( $pattern, $serialized );
		return $serialized;
	}

	public function assert_no_registered_fallbacks( string $pattern, string $content ): void {
		$fingerprints = array();
		foreach ( $this->slots_for_pattern( $pattern ) as $slot ) {
			if ( 'keep-default' !== $slot['on_missing'] && '' !== $slot['fallback_fingerprint'] ) {
				$fingerprints[] = $slot['fallback_fingerprint'];
			}
		}
		if ( empty( $fingerprints ) ) {
			return;
		}

		foreach ( $this->leaf_text_fingerprints( parse_blocks( $content ) ) as $fingerprint ) {
			if ( in_array( $fingerprint, $fingerprints, true ) ) {
				throw new Execution_Exception( 'pattern_fallback_copy_remaining', 'Registered pattern fallback copy remains in materialized public content.' );
			}
		}
	}

	private function transform_target( array $blocks, array $slot, mixed $value, bool $remove ): array {
		$seen  = 0;
		$found = false;
		$result = $this->walk_blocks(
			$blocks,
			function ( array $block ) use ( $slot, $value, $remove, &$seen, &$found ): ?array {
				if ( ! $this->block_has_class( $block, $slot['class'] ) ) {
					return $block;
				}
				++$seen;
				if ( $seen !== $slot['target']['occurrence'] ) {
					return $block;
				}
				$found = true;
				if ( $remove ) {
					return null;
				}
				return $this->replace_block_value( $block, $slot['type'], $value );
			}
		);
		if ( ! $found ) {
			throw new Execution_Exception( 'semantic_slot_target_missing', 'A declared semantic slot target is absent from the registered pattern.' );
		}
		return $result;
	}

	private function walk_blocks( array $blocks, callable $callback ): array {
		$result = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$block = $callback( $block );
			if ( null === $block ) {
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$original_children = array_values( $block['innerBlocks'] );
				$new_children      = array();
				$new_content       = array();
				$child_index       = 0;
				foreach ( (array) ( $block['innerContent'] ?? array() ) as $part ) {
					if ( null !== $part ) {
						$new_content[] = $part;
						continue;
					}
					$child = $original_children[ $child_index ] ?? null;
					++$child_index;
					if ( ! is_array( $child ) ) {
						continue;
					}
					$transformed = $this->walk_blocks( array( $child ), $callback );
					if ( ! empty( $transformed ) ) {
						$new_children[] = $transformed[0];
						$new_content[]  = null;
					}
				}
				$block['innerBlocks']  = $new_children;
				$block['innerContent'] = $new_content;
			}
			$result[] = $block;
		}
		return $result;
	}

	private function replace_block_value( array $block, string $type, mixed $value ): array {
		return match ( $type ) {
			'text', 'heading' => $this->replace_text( $block, $value ),
			'action'          => $this->replace_action( $block, $value ),
			'media'           => $this->replace_media( $block, $value ),
			default           => throw new Execution_Exception( 'semantic_slot_value_unsupported', 'This semantic slot type does not accept an agent-supplied scalar value.' ),
		};
	}

	private function replace_text( array $block, mixed $value ): array {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 20000 ) {
			throw new Execution_Exception( 'semantic_text_invalid', 'Semantic text and heading slots require a non-empty string of at most 20,000 bytes.' );
		}
		$html = (string) ( $block['innerHTML'] ?? '' );
		$replacement = preg_replace( '/^(<[^>]+>).*?(<\/[^>]+>)$/s', '$1' . esc_html( $value ) . '$2', $html, 1 );
		if ( ! is_string( $replacement ) || $replacement === $html ) {
			throw new Execution_Exception( 'semantic_text_target_invalid', 'A semantic text target does not contain one canonical text element.' );
		}
		$block['innerHTML']   = $replacement;
		$block['innerContent'] = array( $replacement );
		return $block;
	}

	private function replace_action( array $block, mixed $value ): array {
		if ( ! is_array( $value ) || array_diff( array_keys( $value ), array( 'label', 'url' ) ) ) {
			throw new Execution_Exception( 'semantic_action_invalid', 'Action slots require only a label and URL.' );
		}
		$label = isset( $value['label'] ) && is_string( $value['label'] ) ? trim( $value['label'] ) : '';
		$url   = isset( $value['url'] ) && is_string( $value['url'] ) ? esc_url( $value['url'], array( 'https', 'http', 'mailto', 'tel' ) ) : '';
		if ( '' === $label || '' === $url || strlen( $label ) > 500 ) {
			throw new Execution_Exception( 'semantic_action_invalid', 'Action slots require a non-empty label and an allowed absolute URL.' );
		}
		$html = (string) ( $block['innerHTML'] ?? '' );
		$replacement = preg_replace_callback(
			'/<a\b([^>]*)>(.*?)<\/a>/s',
			static function ( array $match ) use ( $label, $url ): string {
				$attrs = preg_replace( '/\s+href=("|\').*?\1/i', '', $match[1] );
				return '<a' . $attrs . ' href="' . esc_attr( $url ) . '">' . esc_html( $label ) . '</a>';
			},
			$html,
			1
		);
		if ( ! is_string( $replacement ) || $replacement === $html ) {
			throw new Execution_Exception( 'semantic_action_target_invalid', 'A semantic action target does not contain one canonical link.' );
		}
		$block['innerHTML']    = $replacement;
		$block['innerContent'] = array( $replacement );
		return $block;
	}

	private function replace_media( array $block, mixed $value ): array {
		if ( ! is_array( $value ) || array_diff( array_keys( $value ), array( 'id', 'url', 'alt' ) ) ) {
			throw new Execution_Exception( 'semantic_media_invalid', 'Media slots accept only id, URL, and alt fields.' );
		}
		$url = isset( $value['url'] ) && is_string( $value['url'] ) ? esc_url( $value['url'], array( 'https', 'http' ) ) : '';
		$alt = isset( $value['alt'] ) && is_string( $value['alt'] ) ? $value['alt'] : '';
		$id  = absint( $value['id'] ?? 0 );
		if ( '' === $url || strlen( $alt ) > 1000 ) {
			throw new Execution_Exception( 'semantic_media_invalid', 'Media slots require an allowed image URL and bounded alt text.' );
		}
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		if ( $id > 0 ) {
			$attrs['id'] = $id;
		}
		$attrs['url'] = $url;
		$attrs['alt'] = $alt;
		$block['attrs'] = $attrs;
		$html = (string) ( $block['innerHTML'] ?? '' );
		$html = preg_replace( '/\s+src=("|\').*?\1/i', '', $html, 1 );
		$html = preg_replace( '/\s+alt=("|\').*?\1/i', '', (string) $html, 1 );
		$html = preg_replace( '/<img\b/i', '<img src="' . esc_attr( $url ) . '" alt="' . esc_attr( $alt ) . '"', (string) $html, 1 );
		$block['innerHTML'] = (string) $html;
		$block['innerContent'] = array( (string) $html );
		return $block;
	}

	private function block_has_class( array $block, string $class ): bool {
		$class_name = (string) ( $block['attrs']['className'] ?? '' );
		return in_array( $class, preg_split( '/\s+/', trim( $class_name ) ) ?: array(), true );
	}

	private function is_empty_value( mixed $value ): bool {
		return null === $value || ( is_string( $value ) && '' === trim( $value ) );
	}

	private function normalize_slots( mixed $slots ): array {
		if ( ! is_array( $slots ) || count( $slots ) > 200 ) {
			return array();
		}
		$result = array();
		foreach ( $slots as $slot_id => $slot ) {
			$slot_id = sanitize_key( (string) $slot_id );
			if ( '' === $slot_id || ! is_array( $slot ) ) {
				continue;
			}
			$class      = (string) ( $slot['class'] ?? '' );
			$type       = (string) ( $slot['type'] ?? '' );
			$on_missing = (string) ( $slot['on_missing'] ?? '' );
			$target     = is_array( $slot['target'] ?? null ) ? $slot['target'] : array();
			$occurrence = absint( $target['occurrence'] ?? 0 );
			$fingerprint = (string) ( $slot['fallback_fingerprint'] ?? '' );
			if (
				! preg_match( '/^[a-z][a-z0-9_-]*$/', $class )
				|| ! in_array( $type, array( 'text', 'heading', 'action', 'media', 'items', 'navigation', 'metadata' ), true )
				|| ! in_array( $on_missing, array( 'reject', 'remove', 'keep-default' ), true )
				|| 'class-occurrence' !== (string) ( $target['strategy'] ?? '' )
				|| $occurrence < 1
				|| ( '' !== $fingerprint && ! preg_match( '/^sha256:[a-f0-9]{64}$/', $fingerprint ) )
			) {
				throw new Execution_Exception( 'invalid_semantic_slot_contract', 'The active theme exposes an invalid semantic slot contract.' );
			}
			$result[ $slot_id ] = array(
				'class'                => $class,
				'type'                 => $type,
				'required'             => true === ( $slot['required'] ?? false ),
				'required_for_agent'   => true === ( $slot['required_for_agent'] ?? false ),
				'on_missing'           => $on_missing,
				'target'               => array( 'strategy' => 'class-occurrence', 'occurrence' => $occurrence ),
				'fallback_fingerprint' => $fingerprint,
			);
		}
		return $result;
	}

	private function manifest(): array {
		if ( null !== $this->manifest ) {
			return $this->manifest;
		}
		$filtered = apply_filters( 'smartcloud_composer_semantic_slot_manifest', null );
		if ( is_array( $filtered ) ) {
			$this->manifest = $filtered;
			return $this->manifest;
		}
		$theme = wp_get_theme();
		$file  = trailingslashit( $theme->get_stylesheet_directory() ) . 'smartcloud-agent-composer.json';
		if ( ! is_readable( $file ) || filesize( $file ) > 262144 ) {
			$this->manifest = array();
			return $this->manifest;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Bounded local active-theme contract.
		$this->manifest = is_array( $data ) ? $data : array();
		return $this->manifest;
	}

	private function leaf_text_fingerprints( array $blocks ): array {
		$result = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( empty( $block['innerBlocks'] ) ) {
				$text = trim( html_entity_decode( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( '' !== $text ) {
					$normalized = preg_replace( '/\s+/u', ' ', $text );
					$normalized = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $normalized, 'UTF-8' ) : strtolower( (string) $normalized );
					$result[] = 'sha256:' . hash( 'sha256', trim( $normalized ) );
				}
			}
			$result = array_merge( $result, $this->leaf_text_fingerprints( (array) ( $block['innerBlocks'] ?? array() ) ) );
		}
		return $result;
	}
}
