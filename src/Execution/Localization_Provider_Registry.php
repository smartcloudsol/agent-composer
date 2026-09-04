<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

/**
 * Discovers language plugins through a data-only manifest and WordPress Abilities.
 *
 * This registry is intentionally separate from block component providers. A
 * localization provider owns language identity and relationship validation; it
 * never owns authored strings and cannot merge a proposal through MCP.
 */
final class Localization_Provider_Registry {
	public const FILTER = 'smartcloud_composer_localization_providers';

	private const REQUIRED_SUFFIXES = array(
		'/get-localization-capabilities',
		'/list-content-languages',
		'/resolve-localized-content',
		'/preview-localized-proposal',
		'/validate-localized-proposal',
	);

	private ?array $providers = null;

	public function __construct( private readonly Config_Repository $config ) {}

	public function reset(): void {
		$this->providers = null;
	}

	public function all(): array {
		if ( null !== $this->providers ) {
			return $this->providers;
		}
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) ) {
			return $this->providers = array();
		}
		$manifests = apply_filters( self::FILTER, array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The prefixed hook name is held in the public contract constant.
		if ( ! is_array( $manifests ) ) {
			return $this->providers = array();
		}
		$providers = array();
		$claimed_namespaces = array();
		$claimed_abilities = array();
		foreach ( array_slice( $manifests, 0, 10, true ) as $key => $manifest ) {
			$provider = $this->normalize( $key, $manifest );
			if ( null === $provider
				|| isset( $claimed_namespaces[ $provider['ability_namespace'] ] )
				|| array_intersect( $provider['ability_names'], array_keys( $claimed_abilities ) ) ) {
				continue;
			}
			$claimed_namespaces[ $provider['ability_namespace'] ] = true;
			foreach ( $provider['ability_names'] as $ability_name ) {
				$claimed_abilities[ $ability_name ] = true;
			}
			$providers[ $provider['id'] ] = $provider;
		}
		ksort( $providers );
		return $this->providers = $providers;
	}

	public function public_manifests(): array {
		return array_values( $this->all() );
	}

	public function mcp_ability_names(): array {
		$names = array();
		foreach ( $this->all() as $provider ) {
			$names = array_merge( $names, $provider['mcp_ability_names'] );
		}
		return array_values( array_unique( $names ) );
	}

	public function resolve( int $post_id, string $post_type ): array {
		if ( $post_id < 1 || ! current_user_can( 'read_post', $post_id ) ) {
			throw new Execution_Exception( 'localization_content_read_denied', 'The localized content item is not readable by the current user.' );
		}
		$provider = $this->active_provider();
		if ( null === $provider ) {
			$wordpress_locale = sanitize_text_field( (string) get_locale() );
			$content_language = str_replace( '_', '-', $wordpress_locale );
			if ( ! preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $content_language ) ) {
				throw new Execution_Exception( 'localization_language_invalid', 'WordPress returned an invalid BCP 47 content language.' );
			}
			return array(
				'provider'           => 'wordpress',
				'language_code'      => sanitize_key( (string) strtok( $content_language, '-' ) ),
				'content_language'   => $content_language,
				'locale'             => $wordpress_locale,
				'localization_group' => $post_type . ':' . $post_id,
				'element_type'       => 'post_' . $post_type,
				'source_language_code' => '',
				'translations'       => array(),
			);
		}
		$result = $this->execute( $provider, '/resolve-localized-content', array( 'post_id' => $post_id, 'post_type' => $post_type ) );
		$translations = array();
		foreach ( array_slice( (array) ( $result['translations'] ?? array() ), 0, 50, true ) as $code => $translation_id ) {
			$code = sanitize_key( (string) $code );
			$translation_id = absint( $translation_id );
			if ( '' !== $code && $translation_id > 0 && current_user_can( 'read_post', $translation_id ) ) {
				$translations[ $code ] = $translation_id;
			}
		}
		$content_language = trim( (string) ( $result['content_language'] ?? '' ) );
		if ( ! preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $content_language ) ) {
			throw new Execution_Exception( 'localization_language_invalid', 'The localization provider returned an invalid BCP 47 content language.' );
		}
		$language_code = sanitize_key( (string) ( $result['language_code'] ?? '' ) );
		$group = substr( sanitize_text_field( (string) ( $result['localization_group'] ?? '' ) ), 0, 128 );
		if ( '' === $language_code || '' === $group ) {
			throw new Execution_Exception( 'localization_context_incomplete', 'The localization provider returned an incomplete content relationship.' );
		}
		return array(
			'provider'           => $provider['id'],
			'language_code'      => $language_code,
			'content_language'   => $content_language,
			'locale'             => substr( sanitize_text_field( (string) ( $result['locale'] ?? '' ) ), 0, 35 ),
			'localization_group' => $group,
			'element_type'       => sanitize_key( (string) ( $result['element_type'] ?? '' ) ),
			'source_language_code' => sanitize_key( (string) ( $result['source_language_code'] ?? '' ) ),
			'translations'       => $translations,
		);
	}

	public function languages(): array {
		$provider = $this->active_provider();
		return null === $provider ? array() : $this->execute( $provider, '/list-content-languages', array() );
	}

	public function supported_languages(): array {
		$policy = (array) ( $this->config->get_design_policy()['localization'] ?? array() );
		$allowlist = array_values( array_filter( array_map( 'strval', (array) ( $policy['allowed_content_languages'] ?? array() ) ) ) );
		$unrestricted = empty( $allowlist ) || in_array( '*', $allowlist, true );
		$provider = $this->active_provider();
		if ( null === $provider ) {
			return array(
				'authoring_mode'       => $unrestricted ? 'any-language' : 'allowlist',
				'authorable_languages' => $unrestricted ? array( '*' ) : $allowlist,
				'localization_available' => false,
				'language_switching'   => false,
				'draft_linking'        => false,
				'draft_group_attachment' => false,
				'provider'             => '',
				'languages'            => array(),
			);
		}

		$result = $this->execute( $provider, '/list-content-languages', array() );
		$items = array();
		foreach ( array_slice( (array) ( $result['items'] ?? array() ), 0, 100 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$tag = trim( (string) ( $item['content_language'] ?? '' ) );
			$code = sanitize_key( (string) ( $item['language_code'] ?? '' ) );
			if ( '' === $code || ! preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $tag ) ) {
				continue;
			}
			if ( ! $unrestricted && ! in_array( strtolower( $tag ), array_map( 'strtolower', $allowlist ), true ) ) {
				continue;
			}
			$items[] = array(
				'language_code'    => $code,
				'content_language' => $tag,
				'native_name'      => substr( sanitize_text_field( (string) ( $item['native_name'] ?? $code ) ), 0, 100 ),
				'active'           => true === ( $item['active'] ?? true ),
			);
		}
		$linking = null !== $this->ability_with_suffix( $provider['ability_names'], '/link-draft-translations' );
		$attachment = null !== $this->ability_with_suffix( $provider['ability_names'], '/attach-draft-to-translation-group' );
		$active_items = array_values( array_filter( $items, static fn( array $item ): bool => true === $item['active'] ) );
		return array(
			'authoring_mode'       => $unrestricted ? 'provider-languages' : 'allowlist',
			'authorable_languages' => array_values( array_map( static fn( array $item ): string => $item['content_language'], $active_items ) ),
			'localization_available' => true,
			'language_switching'   => ! empty( $active_items ),
			'draft_linking'        => $linking && count( $active_items ) >= 2,
			'draft_group_attachment' => $attachment && count( $active_items ) >= 2,
			'provider'             => $provider['id'],
			'languages'            => $items,
		);
	}

	public function assign_draft_language( int $post_id, string $post_type, string $content_language ): array {
		$provider = $this->active_provider();
		if ( null === $provider ) {
			return array( 'provider' => 'wordpress', 'post_id' => $post_id, 'language_code' => '', 'content_language' => $content_language, 'assigned' => false );
		}
		$name = $this->ability_with_suffix( $provider['ability_names'], '/assign-draft-language' );
		if ( null === $name ) {
			return array( 'provider' => $provider['id'], 'post_id' => $post_id, 'language_code' => '', 'content_language' => $content_language, 'assigned' => false );
		}
		$result = $this->execute( $provider, '/assign-draft-language', array(
			'post_id' => $post_id,
			'post_type' => $post_type,
			'content_language' => $content_language,
		) );
		$language_code = sanitize_key( (string) ( $result['language_code'] ?? '' ) );
		if ( true !== ( $result['assigned'] ?? false ) || '' === $language_code || 0 !== strcasecmp( $content_language, (string) ( $result['content_language'] ?? '' ) ) ) {
			throw new Execution_Exception( 'localization_draft_assignment_failed', 'The localization provider did not assign the requested draft language.' );
		}
		return array(
			'provider' => $provider['id'],
			'post_id' => $post_id,
			'language_code' => $language_code,
			'content_language' => $content_language,
			'assigned' => true,
		);
	}

	public function link_draft_translations( string $page_type, string $post_type, array $drafts ): array {
		$provider = $this->active_provider();
		if ( null === $provider || null === $this->ability_with_suffix( $provider['ability_names'], '/link-draft-translations' ) ) {
			throw new Execution_Exception( 'localization_draft_linking_unavailable', 'The selected localization provider cannot link Composer-owned drafts.' );
		}
		$result = $this->execute( $provider, '/link-draft-translations', array(
			'page_type' => $page_type,
			'post_type' => $post_type,
			'drafts' => $drafts,
		) );
		if ( (string) ( $result['provider'] ?? '' ) !== $provider['id'] || ! is_array( $result['items'] ?? null ) ) {
			throw new Execution_Exception( 'localization_draft_linking_failed', 'The localization provider returned an invalid draft relationship.' );
		}
		return $result;
	}

	public function attach_draft_to_translation_group( string $page_type, string $post_type, int $anchor_post_id, array $draft, string $expected_group ): array {
		$provider = $this->active_provider();
		if ( null === $provider || null === $this->ability_with_suffix( $provider['ability_names'], '/attach-draft-to-translation-group' ) ) {
			throw new Execution_Exception( 'localization_draft_group_attachment_unavailable', 'The selected localization provider cannot attach a Composer-owned draft to an existing translation group.' );
		}
		$result = $this->execute( $provider, '/attach-draft-to-translation-group', array(
			'page_type' => $page_type,
			'post_type' => $post_type,
			'anchor_post_id' => $anchor_post_id,
			'draft' => $draft,
			'expected_localization_group' => $expected_group,
		) );
		if ( (string) ( $result['provider'] ?? '' ) !== $provider['id'] || ! is_array( $result['translations'] ?? null ) ) {
			throw new Execution_Exception( 'localization_draft_group_attachment_failed', 'The localization provider returned an invalid translation group relationship.' );
		}
		return $result;
	}

	public function validate_proposal( array $context, int $source_id, int $proposal_id ): void {
		$provider_id = sanitize_key( (string) ( $context['provider'] ?? '' ) );
		if ( '' === $provider_id ) {
			return;
		}
		if ( 'wordpress' === $provider_id ) {
			$source = get_post( $source_id );
			$current = $source instanceof \WP_Post ? $this->resolve( $source_id, $source->post_type ) : array();
			if ( 'wordpress' !== (string) ( $current['provider'] ?? '' )
				|| ! hash_equals( (string) ( $context['localization_group'] ?? '' ), (string) ( $current['localization_group'] ?? '' ) )
				|| ! hash_equals( strtolower( (string) ( $context['content_language'] ?? '' ) ), strtolower( (string) ( $current['content_language'] ?? '' ) ) ) ) {
				throw new Execution_Exception( 'localization_context_conflict', 'The source localization provider or language changed after this proposal was created.' );
			}
			return;
		}
		$provider = $this->all()[ $provider_id ] ?? null;
		if ( ! is_array( $provider ) ) {
			throw new Execution_Exception( 'localization_provider_unavailable', 'The proposal localization provider is no longer available.' );
		}
		$result = $this->execute(
			$provider,
			'/validate-localized-proposal',
			array( 'source_post_id' => $source_id, 'proposal_post_id' => $proposal_id, 'context' => $context )
		);
		if ( true !== ( $result['valid'] ?? false ) ) {
			throw new Execution_Exception( 'localization_context_conflict', 'The source language relationship changed after this proposal was created.' );
		}
	}

	public function preview_url( int $proposal_id, array $context ): string {
		$post = get_post( $proposal_id );
		$fallback = $post instanceof \WP_Post ? ( get_preview_post_link( $post ) ?: '' ) : '';
		$provider_id = sanitize_key( (string) ( $context['provider'] ?? '' ) );
		if ( '' === $provider_id || 'wordpress' === $provider_id ) {
			return $fallback;
		}
		$provider = $this->all()[ $provider_id ] ?? null;
		if ( ! is_array( $provider ) ) {
			return $fallback;
		}
		try {
			$result = $this->execute(
				$provider,
				'/preview-localized-proposal',
				array(
					'proposal_post_id' => $proposal_id,
					'language_code'    => sanitize_key( (string) ( $context['language_code'] ?? '' ) ),
				)
			);
		} catch ( Execution_Exception ) {
			return $fallback;
		}
		$url = esc_url_raw( (string) ( $result['preview_url'] ?? '' ), array( 'http', 'https' ) );
		return '' !== $url ? $url : $fallback;
	}

	private function active_provider(): ?array {
		$selection = (string) ( $this->config->get_design_policy()['localization']['provider'] ?? 'auto' );
		if ( 'none' === $selection ) {
			return null;
		}
		$active = array_values( array_filter( $this->all(), static fn( array $provider ): bool => ! empty( $provider['active'] ) ) );
		if ( 'auto' !== $selection ) {
			$selected = $this->all()[ $selection ] ?? null;
			if ( ! is_array( $selected ) || empty( $selected['active'] ) ) {
				throw new Execution_Exception( 'localization_provider_required', 'The Site Contract-required localization provider is unavailable.' );
			}
			return $selected;
		}
		if ( count( $active ) > 1 ) {
			throw new Execution_Exception( 'localization_provider_conflict', 'More than one localization provider reports itself active.' );
		}
		return $active[0] ?? null;
	}

	private function execute( array $provider, string $suffix, array $input ): array {
		$name = $this->ability_with_suffix( $provider['ability_names'], $suffix );
		$ability = null !== $name && wp_has_ability( $name ) ? wp_get_ability( $name ) : null;
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'execute' ) ) {
			throw new Execution_Exception( 'localization_ability_unavailable', 'A required localization ability is unavailable.' );
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			$provider_error = sanitize_key( (string) $result->get_error_code() );
			$suffix = '' !== $provider_error ? ' Provider error: ' . $provider_error . '.' : '';
			throw new Execution_Exception( 'localization_ability_failed', 'The localization provider could not complete its operation.' . $suffix ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sanitized provider error code; never rendered as HTML.
		}
		if ( ! is_array( $result ) ) {
			throw new Execution_Exception( 'localization_ability_failed', 'The localization provider could not complete its operation.' );
		}
		return $result;
	}

	private function normalize( mixed $key, mixed $manifest ): ?array {
		if ( ! is_string( $key ) || ! is_array( $manifest ) || $key !== sanitize_key( $key ) ) {
			return null;
		}
		$keys = array_keys( $manifest );
		sort( $keys );
		if ( $keys !== array( 'ability_names', 'ability_namespace', 'active', 'contract_version', 'label', 'mcp_ability_names', 'plugin_version', 'storage_model' ) ) {
			return null;
		}
		$namespace = sanitize_key( (string) ( $manifest['ability_namespace'] ?? '' ) );
		if ( '' === $namespace || $namespace !== (string) $manifest['ability_namespace'] ) {
			return null;
		}
		$abilities = $this->ability_names( $manifest['ability_names'] );
		$mcp = $this->ability_names( $manifest['mcp_ability_names'] );
		if ( null === $abilities || null === $mcp ) {
			return null;
		}
		foreach ( array_merge( $abilities, $mcp ) as $ability ) {
			if ( ! str_starts_with( $ability, $namespace . '/' ) ) {
				return null;
			}
		}
		foreach ( self::REQUIRED_SUFFIXES as $suffix ) {
			if ( null === $this->ability_with_suffix( $abilities, $suffix ) ) {
				return null;
			}
		}
		if ( array_diff( $mcp, $abilities ) ) {
			return null;
		}
		foreach ( $abilities as $ability ) {
			if ( ! wp_has_ability( $ability ) ) {
				return null;
			}
		}
		return array(
			'id'                => $key,
			'ability_namespace' => $namespace,
			'label'             => sanitize_text_field( (string) ( $manifest['label'] ?? $key ) ),
			'contract_version'  => sanitize_text_field( (string) ( $manifest['contract_version'] ?? '' ) ),
			'plugin_version'    => sanitize_text_field( (string) ( $manifest['plugin_version'] ?? '' ) ),
			'storage_model'     => sanitize_key( (string) ( $manifest['storage_model'] ?? '' ) ),
			'active'            => true === ( $manifest['active'] ?? false ),
			'ability_names'     => $abilities,
			'mcp_ability_names' => $mcp,
		);
	}

	private function ability_with_suffix( array $names, string $suffix ): ?string {
		foreach ( $names as $name ) {
			if ( str_ends_with( $name, $suffix ) ) {
				return $name;
			}
		}
		return null;
	}

	private function ability_names( mixed $value ): ?array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 20 ) {
			return null;
		}
		$result = array();
		foreach ( $value as $name ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,63}\/[a-z0-9][a-z0-9-]{0,95}$/', $name ) ) {
				return null;
			}
			$result[] = $name;
		}
		return array_values( array_unique( $result ) );
	}
}
