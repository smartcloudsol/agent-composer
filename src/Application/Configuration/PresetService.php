<?php

namespace SmartCloud\AgentComposer\Application\Configuration;

use InvalidArgumentException;
use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;
use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;

final class PresetService {
	public const UNIVERSAL   = 'universal-gutenberg';
	public const RECOMMENDED = 'smartcloud-recommended';
	public const DETECTED    = 'detected-theme-starter';

	public function __construct(
		private readonly WordPressConfigurationRepository $repository,
		private readonly ConfigPackageImporter $importer,
		private readonly SiteDiscoveryService $discovery,
		private readonly AuditTable $audit
	) {}

	public function catalogue(): array {
		$discovery = $this->discovery->discover( false );
		$candidate = PresetPatternRegistry::detected_source_pattern();
		return array(
			'items' => array(
				array(
					'id'          => self::UNIVERSAL,
					'label'       => 'Universal Gutenberg',
					'description' => 'The smallest portable page contract, built only from stable core Gutenberg blocks.',
					'kind'        => 'built-in',
					'version'     => '1.0.0',
					'available'   => true,
				),
				array(
					'id'          => self::RECOMMENDED,
					'label'       => 'SmartCloud Recommended',
					'description' => 'A practical portable page contract with enough structure for most marketing and information pages.',
					'kind'        => 'built-in',
					'version'     => '1.0.0',
					'available'   => true,
				),
				array(
					'id'          => self::DETECTED,
					'label'       => 'Detected Theme Starter',
					'description' => 'A site-local contract generated from a safe full-page pattern supplied by the active theme.',
					'kind'        => 'generated',
					'version'     => '1.0.0',
					'available'   => null !== $candidate,
					'availability_reason' => $candidate
						? ''
						: 'The active theme does not expose a safe one-root page pattern without its own H1.',
					'theme'       => array(
						'name'        => $discovery['theme']['name'],
						'version'     => $discovery['theme']['version'],
						'fingerprint' => $discovery['theme_fingerprint'],
					),
					'pattern'     => $candidate ? array_intersect_key( $candidate, array_flip( array( 'name', 'title', 'block_names' ) ) ) : null,
				),
			),
		);
	}

	public function instantiate( string $preset_id, string $label = '' ): array {
		$preset_id = sanitize_key( $preset_id );
		if ( ! in_array( $preset_id, array( self::UNIVERSAL, self::RECOMMENDED, self::DETECTED ), true ) ) {
			throw new InvalidArgumentException( 'Unknown Composer preset.' );
		}
		PresetPatternRegistry::register();
		$discovery = $this->discovery->discover( false );
		$candidate = self::DETECTED === $preset_id ? PresetPatternRegistry::detected_source_pattern() : null;
		if ( self::DETECTED === $preset_id && null === $candidate ) {
			throw new InvalidArgumentException( 'The active theme does not expose a safe full-page starter pattern.' );
		}

		$package_id = $preset_id . '-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 );
		$label      = sanitize_text_field( $label );
		if ( '' === $label ) {
			$label = match ( $preset_id ) {
				self::UNIVERSAL   => 'Universal Gutenberg',
				self::RECOMMENDED => 'SmartCloud Recommended',
				default           => 'Detected Theme Starter: ' . $discovery['theme']['name'],
			};
		}
		$package = $this->package( $package_id, $label, $preset_id, $discovery, $candidate );
		$result  = $this->importer->import( $package, false );
		$this->audit->record( 'preset-instantiated', 'success', array(
			'preset_id'         => $preset_id,
			'preset_version'    => '1.0.0',
			'config_set'        => $package_id,
			'theme_fingerprint' => $discovery['theme_fingerprint'],
		) );
		return array(
			'preset'     => $preset_id,
			'config_set' => $this->repository->describe_config_set( $result['config_set'] ),
			'active'     => false,
		);
	}

	private function package( string $package_id, string $label, string $preset_id, array $discovery, ?array $candidate ): array {
		$patterns = match ( $preset_id ) {
			self::UNIVERSAL => array(
				'smartcloud-composer/universal-hero',
				'smartcloud-composer/universal-content',
				'smartcloud-composer/universal-cta',
			),
			self::RECOMMENDED => array(
				'smartcloud-composer/recommended-hero',
				'smartcloud-composer/recommended-features',
				'smartcloud-composer/recommended-steps',
				'smartcloud-composer/recommended-faq',
				'smartcloud-composer/recommended-cta',
			),
			default => array( PresetPatternRegistry::DETECTED_PATTERN ),
		};
		$required = self::RECOMMENDED === $preset_id
			? array_values( array_diff( $patterns, array( 'smartcloud-composer/recommended-faq' ) ) )
			: $patterns;
		$blocks = self::DETECTED === $preset_id
			? $candidate['block_names']
			: array( 'core/group', 'core/heading', 'core/paragraph', 'core/buttons', 'core/button', 'core/columns', 'core/column', 'core/list', 'core/list-item', 'core/details' );
		$namespace = strstr( $patterns[0], '/', true );
		$template  = $this->detected_template( $discovery['registered_templates'] );

		$manifest = array(
			'schema_version'   => '1.0',
			'config_set_id'    => $package_id,
			'label'            => $label,
			'mode'             => self::DETECTED === $preset_id ? 'detected-theme' : 'universal-gutenberg',
			'fallback_policy'  => 'strict',
			'preset'           => array(
				'id'                   => $preset_id,
				'version'              => '1.0.0',
				'kind'                 => self::DETECTED === $preset_id ? 'generated' : 'built-in',
				'theme_stylesheet'     => $discovery['theme']['stylesheet'],
				'theme_version'        => $discovery['theme']['version'],
				'theme_fingerprint'    => $discovery['theme_fingerprint'],
				'capability_fingerprint' => $discovery['site_capability_fingerprint'],
				'detected_source_pattern' => self::DETECTED === $preset_id ? $candidate['name'] : '',
			),
			'entities'         => array( 'contract:site', 'page' ),
		);
		$contract = array(
			'schema_version' => '1.0',
			'entity_type'    => 'site-contract',
			'key'            => 'site',
			'label'          => $label . ' site contract',
			'design_policy'  => array(
				'schema_version'             => 1,
				'policy_name'                => $label,
				'policy_version'             => '1.0.0',
				'allowed_pattern_namespaces' => array( $namespace ),
				'post_type_contract'         => array( 'page' => 'page' ),
				'disallowed_blocks'          => array( 'core/html', 'core/shortcode', 'core/freeform', 'core/legacy-widget', 'core/widget-group', 'core/embed' ),
				'constraints'                => array(
					'exactly_one_h1'    => true,
					'inline_css'        => false,
					'custom_html'       => false,
					'shortcodes'        => false,
					'external_embeds'   => false,
					'theme_presets_only' => true,
					'maximum_words'     => 3000,
				),
			),
		);
		$blueprint = array(
			'schema_version'    => 1,
			'entity_type'       => 'blueprint',
			'page_type'         => 'page',
			'label'             => $label . ' page',
			'purpose'           => 'Create a clear, accessible WordPress page that follows the active theme and the selected Composer starter contract.',
			'target_post_type'  => 'page',
			'target_template'   => $template,
			'excerpt_policy'    => 'optional',
			'allowed_patterns'  => $patterns,
			'required_sequence' => $required,
			'allowed_blocks'    => array_values( array_unique( array_filter( $blocks, static fn( string $name ): bool => str_starts_with( $name, 'core/' ) ) ) ),
			'constraints'       => array(
				'exactly_one_h1'    => true,
				'inline_css'        => false,
				'custom_html'       => false,
				'shortcodes'        => false,
				'external_embeds'   => false,
				'theme_presets_only' => true,
				'maximum_words'     => 2400,
			),
			'content_contract'   => array(
				'Use one descriptive H1 and a clear heading hierarchy.',
				'Use only the approved Gutenberg patterns and registered core blocks.',
				'Keep claims factual and end with a useful next step when appropriate.',
			),
		);

		$entities = array(
			$this->entity( $package_id, EntityType::CONFIG_SET, $manifest ),
			$this->entity( 'contract:site', EntityType::SITE_CONTRACT, $contract ),
			$this->entity( 'page', EntityType::BLUEPRINT, $blueprint ),
		);
		return array(
			'schema_version' => '1.0.0-rc.1',
			'activation'     => 'working-set-only',
			'package'        => array( 'id' => $package_id, 'exported_gmt' => gmdate( 'c' ), 'source_site' => home_url( '/' ) ),
			'entities'       => $entities,
			'checksums'      => array( 'entities' => CanonicalJson::checksum( $entities ) ),
		);
	}

	private function entity( string $id, string $type, array $payload ): array {
		return array( 'id' => $id, 'type' => $type, 'payload' => $payload, 'checksum' => CanonicalJson::checksum( $payload ) );
	}

	private function detected_template( array $templates ): array {
		foreach ( array( 'page-no-title', 'page' ) as $preferred ) {
			foreach ( $templates as $template ) {
				if ( $preferred === (string) ( $template['slug'] ?? '' ) ) {
					return array( 'label' => (string) ( $template['title'] ?? $preferred ), 'slug' => $preferred );
				}
			}
		}
		return array( 'label' => 'Default', 'slug' => 'default' );
	}

}
