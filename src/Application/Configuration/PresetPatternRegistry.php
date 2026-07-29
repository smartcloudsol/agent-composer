<?php

namespace SmartCloud\AgentComposer\Application\Configuration;

final class PresetPatternRegistry {
	public const DETECTED_PATTERN = 'smartcloud-composer/detected-theme-starter';

	public static function register(): void {
		if ( ! function_exists( 'register_block_pattern' ) ) {
			return;
		}

		foreach ( self::patterns() as $name => $pattern ) {
			if ( \WP_Block_Patterns_Registry::get_instance()->is_registered( $name ) ) {
				continue;
			}
			register_block_pattern( $name, $pattern );
		}

		$detected = self::detected_source_pattern();
		if ( $detected && ! \WP_Block_Patterns_Registry::get_instance()->is_registered( self::DETECTED_PATTERN ) ) {
			register_block_pattern(
				self::DETECTED_PATTERN,
				array(
					'title'       => __( 'Detected theme starter', 'smartcloud-agent-composer' ),
					/* translators: %s: title of the active theme pattern used to generate the starter. */
					'description' => sprintf( __( 'A generated starter based on the active theme pattern: %s.', 'smartcloud-agent-composer' ), $detected['title'] ),
					'categories'  => array( 'featured', 'text' ),
					'content'     => '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">{{wpsuite:text:title}}</h1><!-- /wp:heading --><!-- wp:paragraph --><p>{{wpsuite:text:introduction}}</p><!-- /wp:paragraph -->' . serialize_block( $detected['root'] ) . '</div><!-- /wp:group -->',
				)
			);
		}
	}

	public static function detected_source_pattern(): ?array {
		if ( ! class_exists( '\\WP_Block_Patterns_Registry' ) ) {
			return null;
		}
		$theme      = wp_get_theme();
		$namespaces = array_values( array_unique( array_filter( array( sanitize_key( $theme->get_stylesheet() ), sanitize_key( $theme->get_template() ) ) ) ) );
		$candidates = array();
		foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$name = strtolower( trim( (string) ( is_array( $pattern ) ? ( $pattern['name'] ?? '' ) : '' ) ) );
			if (
				! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name )
				|| ! in_array( strstr( $name, '/', true ), $namespaces, true )
				|| preg_match( '/(?:^|\/)(?:hidden|template|header|footer|comments?|sidebar|post|query|search|404)(?:-|$)/', $name )
			) {
				continue;
			}
			$content = trim( (string) ( $pattern['content'] ?? '' ) );
			$blocks  = parse_blocks( $content );
			$roots   = array_values( array_filter( $blocks, static fn( array $block ): bool => null !== ( $block['blockName'] ?? null ) ) );
			$meaningful = array_values( array_filter( $blocks, static fn( array $block ): bool => null !== ( $block['blockName'] ?? null ) || '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) );
			if ( 1 !== count( $roots ) || 1 !== count( $meaningful ) ) {
				continue;
			}
			$inventory = self::block_inventory( $roots );
			$forbidden = array( 'core/pattern', 'core/query', 'core/navigation', 'core/template-part', 'core/post-template', 'core/post-content', 'core/comments', 'core/search', 'core/shortcode', 'core/html', 'core/freeform' );
			$categories = array_map( 'sanitize_key', (array) ( $pattern['categories'] ?? array() ) );
			$relevant_category = ! empty(
				array_filter(
					$categories,
					static fn( string $category ): bool => in_array( $category, array( 'page', 'featured', 'banner', 'about' ), true ) || str_ends_with( $category, '_page' )
				)
			);
			if (
				0 !== $inventory['h1']
				|| array_intersect( $forbidden, $inventory['names'] )
				|| array_filter( $inventory['names'], static fn( string $block ): bool => ! str_starts_with( $block, 'core/' ) )
				|| ( ! $relevant_category && ! preg_match( '/(?:intro|hero|banner)/', $name ) )
			) {
				continue;
			}
			$score      = 0;
			$score     += count( array_intersect( $categories, array( 'page', 'featured' ) ) ) * 30;
			$score     += count( array_filter( $categories, static fn( string $category ): bool => str_ends_with( $category, '_page' ) ) ) * 15;
			$score     += count( array_intersect( $categories, array( 'banner', 'about' ) ) ) * 20;
			$score     += preg_match( '/(?:intro|hero|banner)/', $name ) ? 25 : 0;
			$score     += in_array( 'core/paragraph', $inventory['names'], true ) ? 10 : 0;
			$score     += in_array( 'core/buttons', $inventory['names'], true ) ? 10 : 0;
			$score     += in_array( 'core/image', $inventory['names'], true ) ? 5 : 0;
			$score     -= preg_match( '/(?:coming-soon|link-in-bio|shop|portfolio|podcast|book|event|cv)/', $name ) ? 100 : 0;
			$candidates[] = array(
				'name'        => $name,
				'title'       => sanitize_text_field( (string) ( $pattern['title'] ?? $name ) ),
				'block_names' => array_values( array_unique( array_merge( array( 'core/group', 'core/heading', 'core/paragraph' ), $inventory['names'] ) ) ),
				'root'        => self::sanitize_styles( $roots[0] ),
				'score'       => $score,
			);
		}
		usort( $candidates, static fn( array $left, array $right ): int => $right['score'] <=> $left['score'] ?: strcmp( $left['name'], $right['name'] ) );
		return $candidates[0] ?? null;
	}

	private static function block_inventory( array $blocks ): array {
		$result = array( 'names' => array(), 'h1' => 0 );
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( '' !== $name ) {
				$result['names'][] = $name;
				if ( 'core/heading' === $name && 1 === (int) ( $block['attrs']['level'] ?? 2 ) ) {
					++$result['h1'];
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$child            = self::block_inventory( $block['innerBlocks'] );
				$result['names'] = array_merge( $result['names'], $child['names'] );
				$result['h1']   += $child['h1'];
			}
		}
		return $result;
	}

	private static function sanitize_styles( array $block ): array {
		if ( isset( $block['attrs']['style'] ) && ! self::preset_style_value( $block['attrs']['style'] ) ) {
			unset( $block['attrs']['style'] );
		}
		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$block['innerHTML'] = self::strip_inline_style_attributes( $block['innerHTML'] );
		}
		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			$block['innerContent'] = array_map(
				static fn( mixed $fragment ): mixed => is_string( $fragment ) ? self::strip_inline_style_attributes( $fragment ) : $fragment,
				$block['innerContent']
			);
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = array_map( array( self::class, 'sanitize_styles' ), $block['innerBlocks'] );
		}
		return $block;
	}

	private static function strip_inline_style_attributes( string $html ): string {
		$sanitized = preg_replace( '/\sstyle=(?:"[^"]*"|\'[^\']*\')/i', '', $html );
		return is_string( $sanitized ) ? $sanitized : $html;
	}

	private static function preset_style_value( mixed $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $child ) {
			if ( is_array( $child ) ) {
				if ( ! self::preset_style_value( $child ) ) {
					return false;
				}
			} elseif ( ! is_string( $child ) || ! preg_match( '/^(?:var:preset\|[a-z0-9-]+\|[a-z0-9-]+|var\(--wp--preset--[a-z0-9-]+--[a-z0-9-]+\))$/i', $child ) ) {
				return false;
			}
		}
		return true;
	}

	public static function patterns(): array {
		return array(
			'smartcloud-composer/universal-hero' => array(
				'title'       => __( 'Universal page hero', 'smartcloud-agent-composer' ),
				'description' => __( 'A theme-neutral heading and introduction.', 'smartcloud-agent-composer' ),
				'categories'  => array( 'featured', 'text' ),
				'content'     => '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">{{wpsuite:text:title}}</h1><!-- /wp:heading --><!-- wp:paragraph --><p>{{wpsuite:text:introduction}}</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
			),
			'smartcloud-composer/universal-content' => array(
				'title'       => __( 'Universal content section', 'smartcloud-agent-composer' ),
				'description' => __( 'A theme-neutral section for the main explanation.', 'smartcloud-agent-composer' ),
				'categories'  => array( 'text' ),
				'content'     => '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">{{wpsuite:text:heading}}</h2><!-- /wp:heading --><!-- wp:paragraph --><p>{{wpsuite:text:body}}</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
			),
			'smartcloud-composer/universal-cta' => array(
				'title'       => __( 'Universal call to action', 'smartcloud-agent-composer' ),
				'description' => __( 'A theme-neutral closing action.', 'smartcloud-agent-composer' ),
				'categories'  => array( 'call-to-action' ),
				'content'     => '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">{{wpsuite:text:heading}}</h2><!-- /wp:heading --><!-- wp:paragraph --><p>{{wpsuite:text:body}}</p><!-- /wp:paragraph --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="{{wpsuite:url:url}}">{{wpsuite:text:label}}</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group -->',
			),
			'smartcloud-composer/recommended-hero' => array(
				'title'       => __( 'Recommended page hero', 'smartcloud-agent-composer' ),
				'description' => __( 'A focused hero with one primary action.', 'smartcloud-agent-composer' ),
				'categories'  => array( 'featured', 'banner' ),
				'content'     => '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:paragraph --><p><strong>{{wpsuite:text:eyebrow}}</strong></p><!-- /wp:paragraph --><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">{{wpsuite:text:title}}</h1><!-- /wp:heading --><!-- wp:paragraph --><p>{{wpsuite:text:introduction}}</p><!-- /wp:paragraph --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="{{wpsuite:url:url}}">{{wpsuite:text:label}}</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group -->',
			),
			'smartcloud-composer/recommended-features' => array(
				'title'       => __( 'Recommended feature grid', 'smartcloud-agent-composer' ),
				'description' => __( 'Three concise outcome-focused feature columns.', 'smartcloud-agent-composer' ),
				'categories'  => array( 'columns', 'services' ),
				'content'     => '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">{{wpsuite:text:heading}}</h2><!-- /wp:heading --><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">{{wpsuite:text:item_one_title}}</h3><!-- /wp:heading --><!-- wp:paragraph --><p>{{wpsuite:text:item_one_body}}</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">{{wpsuite:text:item_two_title}}</h3><!-- /wp:heading --><!-- wp:paragraph --><p>{{wpsuite:text:item_two_body}}</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">{{wpsuite:text:item_three_title}}</h3><!-- /wp:heading --><!-- wp:paragraph --><p>{{wpsuite:text:item_three_body}}</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->',
			),
			'smartcloud-composer/recommended-steps' => array(
				'title'       => __( 'Recommended steps', 'smartcloud-agent-composer' ),
				'description' => __( 'A compact ordered process.', 'smartcloud-agent-composer' ),
				'categories'  => array( 'text' ),
				'content'     => '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">{{wpsuite:text:heading}}</h2><!-- /wp:heading --><!-- wp:list {"ordered":true} --><ol class="wp-block-list"><!-- wp:list-item --><li>{{wpsuite:text:step_one}}</li><!-- /wp:list-item --><!-- wp:list-item --><li>{{wpsuite:text:step_two}}</li><!-- /wp:list-item --><!-- wp:list-item --><li>{{wpsuite:text:step_three}}</li><!-- /wp:list-item --></ol><!-- /wp:list --></div><!-- /wp:group -->',
			),
			'smartcloud-composer/recommended-faq' => array(
				'title'       => __( 'Recommended FAQ', 'smartcloud-agent-composer' ),
				'description' => __( 'Two native Details questions.', 'smartcloud-agent-composer' ),
				'categories'  => array( 'text' ),
				'content'     => '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">{{wpsuite:text:heading}}</h2><!-- /wp:heading --><!-- wp:details --><details class="wp-block-details"><summary>{{wpsuite:text:question_one}}</summary><!-- wp:paragraph {"placeholder":"Type / to add a hidden block"} --><p>{{wpsuite:text:answer_one}}</p><!-- /wp:paragraph --></details><!-- /wp:details --><!-- wp:details --><details class="wp-block-details"><summary>{{wpsuite:text:question_two}}</summary><!-- wp:paragraph {"placeholder":"Type / to add a hidden block"} --><p>{{wpsuite:text:answer_two}}</p><!-- /wp:paragraph --></details><!-- /wp:details --></div><!-- /wp:group -->',
			),
			'smartcloud-composer/recommended-cta' => array(
				'title'       => __( 'Recommended closing action', 'smartcloud-agent-composer' ),
				'description' => __( 'A focused closing action with supporting copy.', 'smartcloud-agent-composer' ),
				'categories'  => array( 'call-to-action' ),
				'content'     => '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">{{wpsuite:text:heading}}</h2><!-- /wp:heading --><!-- wp:paragraph --><p>{{wpsuite:text:body}}</p><!-- /wp:paragraph --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="{{wpsuite:url:url}}">{{wpsuite:text:label}}</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group -->',
			),
		);
	}

	private function __construct() {}
}
