<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

/** Links separately authored Composer drafts without publishing either item. */
final class Localized_Draft_Service {
	private const LOCK_SECONDS = 60;

	public function __construct(
		private readonly Config_Repository $config,
		private readonly Draft_Service $drafts,
		private readonly Localization_Provider_Registry $localization
	) {}

	public function link( array $input ): array {
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		if ( '' === $page_type || true !== ( $input['confirm_link'] ?? false ) ) {
			throw new Execution_Exception( 'localized_draft_link_confirmation_required', 'An exact page type and explicit draft-link confirmation are required.' );
		}
		$requested = $input['drafts'] ?? null;
		if ( ! is_array( $requested ) || ! array_is_list( $requested ) || count( $requested ) < 2 || count( $requested ) > 20 ) {
			throw new Execution_Exception( 'localized_draft_set_invalid', 'A localized draft set must contain between 2 and 20 drafts.' );
		}

		$blueprint = $this->config->get_blueprint( $page_type );
		$post_type = sanitize_key( (string) ( $blueprint['target_post_type'] ?? '' ) );
		$candidates = $this->candidates( $requested, $page_type, $post_type );
		$locks = $this->acquire_locks( array_column( $candidates, 'post_id' ) );
		try {
			$candidates = $this->candidates( $requested, $page_type, $post_type );
			$result = $this->localization->link_draft_translations( $page_type, $post_type, $candidates );
			$returned_ids = array_values( array_unique( array_map( 'absint', array_column( (array) ( $result['items'] ?? array() ), 'post_id' ) ) ) );
			$expected_ids = array_values( array_column( $candidates, 'post_id' ) );
			sort( $returned_ids );
			sort( $expected_ids );
			if ( $returned_ids !== $expected_ids ) {
				throw new Execution_Exception( 'localization_draft_linking_failed', 'The provider did not confirm the complete localized draft set.' );
			}
			$result['page_type'] = $page_type;
			$result['post_type'] = $post_type;
			return $result;
		} finally {
			$this->release_locks( $locks );
		}
	}

	/** Attach one owned draft to an existing provider group without changing any existing slot. */
	public function attach_to_group( array $input ): array {
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$anchor_post_id = absint( $input['anchor_post_id'] ?? 0 );
		$expected_group = substr( sanitize_text_field( (string) ( $input['expected_localization_group'] ?? '' ) ), 0, 128 );
		if ( '' === $page_type || $anchor_post_id < 1 || '' === $expected_group || true !== ( $input['confirm_attach'] ?? false ) ) {
			throw new Execution_Exception( 'localized_draft_group_confirmation_required', 'An exact Blueprint, translation-group anchor, expected group, and explicit confirmation are required.' );
		}
		$requested_draft = $input['draft'] ?? null;
		if ( ! is_array( $requested_draft ) ) {
			throw new Execution_Exception( 'localized_draft_item_invalid', 'The localized draft entry must be an object.' );
		}

		$blueprint = $this->config->get_blueprint( $page_type );
		$post_type = sanitize_key( (string) ( $blueprint['target_post_type'] ?? '' ) );
		$this->drafts->inspect_content_item( array( 'post_id' => $anchor_post_id, 'page_type' => $page_type ) );
		$candidate = $this->candidates( array( $requested_draft ), $page_type, $post_type )[0];
		if ( $anchor_post_id === $candidate['post_id'] ) {
			throw new Execution_Exception( 'localized_draft_group_anchor_invalid', 'The translation-group anchor and attached draft must be different content items.' );
		}

		$context = $this->localization->resolve( $anchor_post_id, $post_type );
		$this->assert_attach_context( $context, $candidate, $expected_group );
		$lock_ids = array_values( array_unique( array_merge(
			array( $anchor_post_id, $candidate['post_id'] ),
			array_map( 'absint', array_values( (array) ( $context['translations'] ?? array() ) ) )
		) ) );
		$locks = $this->acquire_locks( $lock_ids );
		try {
			$this->drafts->inspect_content_item( array( 'post_id' => $anchor_post_id, 'page_type' => $page_type ) );
			$candidate = $this->candidates( array( $requested_draft ), $page_type, $post_type )[0];
			$context = $this->localization->resolve( $anchor_post_id, $post_type );
			if ( $this->assert_attach_context( $context, $candidate, $expected_group ) ) {
				return $this->attachment_result( $page_type, $post_type, $context, $candidate, true );
			}
			$result = $this->localization->attach_draft_to_translation_group( $page_type, $post_type, $anchor_post_id, $candidate, $expected_group );
			$confirmed = $this->localization->resolve( $anchor_post_id, $post_type );
			$language_code = (string) $candidate['language_code'];
			if ( absint( ( $confirmed['translations'] ?? array() )[ $language_code ] ?? 0 ) !== $candidate['post_id'] ) {
				throw new Execution_Exception( 'localization_draft_group_attachment_failed', 'The provider did not confirm the attached draft in the requested language slot.' );
			}
			$result['page_type'] = $page_type;
			$result['post_type'] = $post_type;
			$result['localization_group'] = (string) $confirmed['localization_group'];
			$result['idempotent_replay'] = false;
			return $result;
		} finally {
			$this->release_locks( $locks );
		}
	}

	/** Return true only when the requested draft is already in the target slot. */
	private function assert_attach_context( array $context, array $candidate, string $expected_group ): bool {
		if ( (string) ( $context['provider'] ?? '' ) !== (string) $candidate['localization_provider'] ) {
			throw new Execution_Exception( 'localized_draft_provider_conflict', 'The translation group and draft must use the same localization provider.' );
		}
		$language_code = (string) $candidate['language_code'];
		$occupied_post_id = absint( ( $context['translations'] ?? array() )[ $language_code ] ?? 0 );
		if ( $occupied_post_id === $candidate['post_id'] ) {
			return true;
		}
		if ( $occupied_post_id > 0 ) {
			throw new Execution_Exception( 'localized_draft_language_slot_occupied', 'The requested translation group already contains content in the draft language.' );
		}
		if ( ! hash_equals( $expected_group, (string) ( $context['localization_group'] ?? '' ) ) ) {
			throw new Execution_Exception( 'localized_draft_group_conflict', 'The translation group changed after it was inspected.' );
		}
		return false;
	}

	private function attachment_result( string $page_type, string $post_type, array $context, array $candidate, bool $idempotent ): array {
		return array(
			'provider' => (string) $context['provider'],
			'page_type' => $page_type,
			'post_type' => $post_type,
			'localization_group' => (string) $context['localization_group'],
			'translations' => (array) $context['translations'],
			'item' => $candidate,
			'idempotent_replay' => $idempotent,
		);
	}

	private function candidates( array $requested, string $page_type, string $post_type ): array {
		$candidates = array();
		$seen_ids = array();
		$seen_languages = array();
		$provider = '';
		foreach ( $requested as $item ) {
			if ( ! is_array( $item ) ) {
				throw new Execution_Exception( 'localized_draft_item_invalid', 'Every localized draft entry must be an object.' );
			}
			$post_id = absint( $item['post_id'] ?? 0 );
			if ( $post_id < 1 || isset( $seen_ids[ $post_id ] ) ) {
				throw new Execution_Exception( 'localized_draft_item_invalid', 'Localized draft IDs must be positive and unique.' );
			}
			$post = $this->drafts->get_owned_draft( $post_id );
			if ( $post_type !== $post->post_type || $page_type !== sanitize_key( (string) get_post_meta( $post_id, Draft_Service::PAGE_TYPE_META, true ) ) ) {
				throw new Execution_Exception( 'localized_draft_contract_mismatch', 'Every linked draft must use the same requested Blueprint and target post type.' );
			}
			if ( '' !== sanitize_key( (string) get_post_meta( $post_id, Content_Proposal_Service::STATE_META, true ) ) ) {
				throw new Execution_Exception( 'localized_draft_proposal_forbidden', 'Published-content proposals cannot be linked as new translations.' );
			}
			$content_language = sanitize_text_field( (string) get_post_meta( $post_id, Draft_Service::CONTENT_LANGUAGE_META, true ) );
			$submitted_language = trim( (string) ( $item['content_language'] ?? '' ) );
			if ( '' === $content_language || 0 !== strcasecmp( $content_language, $submitted_language ) ) {
				throw new Execution_Exception( 'localized_draft_language_conflict', 'The requested language must match the immutable language stored on each draft.' );
			}
			$language_key = strtolower( $content_language );
			if ( isset( $seen_languages[ $language_key ] ) ) {
				throw new Execution_Exception( 'localized_draft_language_conflict', 'A localized draft set may contain only one draft per language.' );
			}
			$item_provider = sanitize_key( (string) get_post_meta( $post_id, Draft_Service::LOCALIZATION_PROVIDER_META, true ) );
			$language_code = sanitize_key( (string) get_post_meta( $post_id, Draft_Service::LANGUAGE_CODE_META, true ) );
			if ( '' === $item_provider || 'wordpress' === $item_provider || '' === $language_code || ( '' !== $provider && $provider !== $item_provider ) ) {
				throw new Execution_Exception( 'localized_draft_provider_conflict', 'Every draft must have a language assigned by the same localization provider.' );
			}
			$provider = $item_provider;
			if ( ! hash_equals( $this->modified_token( $post->post_modified_gmt ), (string) ( $item['expected_modified_gmt'] ?? '' ) )
				|| ! hash_equals( (string) get_post_meta( $post_id, Draft_Service::REVISION_META, true ), (string) ( $item['expected_revision'] ?? '' ) ) ) {
				throw new Execution_Exception( 'edit_conflict', 'A localized draft changed after it was inspected.' );
			}
			$seen_ids[ $post_id ] = true;
			$seen_languages[ $language_key ] = true;
			$candidates[] = array(
				'post_id' => $post_id,
				'content_language' => $content_language,
				'language_code' => $language_code,
				'localization_provider' => $item_provider,
			);
		}
		return $candidates;
	}

	private function modified_token( string $value ): string {
		$timestamp = strtotime( $value . ' UTC' );
		return false === $timestamp ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	private function acquire_locks( array $post_ids ): array {
		sort( $post_ids );
		$locks = array();
		foreach ( $post_ids as $post_id ) {
			$name = '_wpsuite_agent_localization_lock_' . absint( $post_id );
			$value = wp_json_encode( array( 'token' => wp_generate_uuid4(), 'expires_at' => time() + self::LOCK_SECONDS ) );
			if ( ! is_string( $value ) || ! $this->acquire_lock( $name, $value ) ) {
				$this->release_locks( $locks );
				throw new Execution_Exception( 'localized_draft_link_conflict', 'Another request is changing one of the localized draft relationships.' );
			}
			$locks[] = array( 'name' => $name, 'value' => $value );
		}
		return $locks;
	}

	private function acquire_lock( string $name, string $value ): bool {
		if ( add_option( $name, $value, '', false ) ) {
			return true;
		}
		$existing = get_option( $name, '' );
		$decoded = is_string( $existing ) ? json_decode( $existing, true ) : null;
		if ( ! is_array( $decoded ) || (int) ( $decoded['expires_at'] ?? 0 ) >= time() ) {
			return false;
		}
		global $wpdb;
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $existing ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact CAS recovers only an expired plugin-owned lease.
		if ( 1 === $updated ) {
			wp_cache_delete( $name, 'options' );
			return true;
		}
		return false;
	}

	private function release_locks( array $locks ): void {
		global $wpdb;
		foreach ( array_reverse( $locks ) as $lock ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock['name'], $lock['value'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact compare-and-delete releases only this request's plugin-owned lease.
			wp_cache_delete( $lock['name'], 'options' );
		}
	}
}
