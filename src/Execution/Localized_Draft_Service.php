<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

/** Manages governed localized-content relationships without changing publication state. */
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
			$returned_ids = array_values( array_unique( array_map( static fn( mixed $value ): int => absint( $value ), array_column( (array) ( $result['items'] ?? array() ), 'post_id' ) ) ) );
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
			array_map( static fn( mixed $value ): int => absint( $value ), array_values( (array) ( $context['translations'] ?? array() ) ) )
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

	/** Attach one inspected draft or published item to an empty slot in an existing group. */
	public function attach_content_to_group( array $input ): array {
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$anchor_post_id = absint( $input['anchor_post_id'] ?? 0 );
		$expected_group = substr( sanitize_text_field( (string) ( $input['expected_localization_group'] ?? '' ) ), 0, 128 );
		$requested = $input['content'] ?? null;
		if ( '' === $page_type || $anchor_post_id < 1 || '' === $expected_group || ! is_array( $requested ) || true !== ( $input['confirm_attach'] ?? false ) ) {
			throw new Execution_Exception( 'localized_content_group_confirmation_required', 'An exact Blueprint, translation-group anchor, inspected content item, expected group, and explicit confirmation are required.' );
		}

		$blueprint = $this->config->get_blueprint( $page_type );
		$post_type = sanitize_key( (string) ( $blueprint['target_post_type'] ?? '' ) );
		$anchor = $this->inspect_editable_content( $anchor_post_id, $page_type, $post_type );
		$candidate = $this->content_candidate( $requested, $page_type, $post_type );
		if ( $anchor_post_id === $candidate['post_id'] ) {
			throw new Execution_Exception( 'localized_content_group_anchor_invalid', 'The translation-group anchor and attached content item must be different.' );
		}

		$context = (array) $anchor['localization'];
		$already_attached = $this->assert_content_attach_context( $context, $candidate, $expected_group );
		$source_context = $this->localization->resolve( $candidate['post_id'], $post_type );
		if ( ! $already_attached ) {
			$this->assert_singleton_source_group( $source_context, $candidate );
		}
		$lock_ids = array_values( array_unique( array_merge(
			array( $anchor_post_id, $candidate['post_id'] ),
			array_map( static fn( mixed $value ): int => absint( $value ), array_values( (array) ( $context['translations'] ?? array() ) ) ),
			array_map( static fn( mixed $value ): int => absint( $value ), array_values( (array) ( $source_context['translations'] ?? array() ) ) )
		) ) );
		$locks = $this->acquire_locks( $lock_ids );
		try {
			$anchor = $this->inspect_editable_content( $anchor_post_id, $page_type, $post_type );
			$candidate = $this->content_candidate( $requested, $page_type, $post_type );
			$context = (array) $anchor['localization'];
			if ( $this->assert_content_attach_context( $context, $candidate, $expected_group ) ) {
				return $this->content_attachment_result( $page_type, $post_type, $context, $candidate, true );
			}
			$source_context = $this->localization->resolve( $candidate['post_id'], $post_type );
			$this->assert_singleton_source_group( $source_context, $candidate );
			$result = $this->localization->attach_content_to_translation_group(
				$page_type,
				$post_type,
				$anchor_post_id,
				$candidate,
				$expected_group,
				$this->normalize_translation_map( (array) ( $context['translations'] ?? array() ) )
			);
			$confirmed = $this->localization->resolve( $anchor_post_id, $post_type );
			$language_code = (string) $candidate['language_code'];
			$expected_translations = $this->merge_translation_maps(
				$this->normalize_translation_map( (array) ( $context['translations'] ?? array() ) ),
				array( $language_code => $candidate['post_id'] )
			);
			if ( absint( ( $confirmed['translations'] ?? array() )[ $language_code ] ?? 0 ) !== $candidate['post_id']
				|| ! $this->same_translation_map( $expected_translations, (array) ( $confirmed['translations'] ?? array() ) ) ) {
				throw new Execution_Exception( 'localization_content_group_attachment_failed', 'The provider did not confirm the exact target group with the content item in the requested language slot.' );
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

	/** Merge two exact, non-conflicting provider groups and preserve every member and status. */
	public function merge_groups( array $input ): array {
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$target_request = $input['target'] ?? null;
		$source_request = $input['source'] ?? null;
		if ( '' === $page_type || ! is_array( $target_request ) || ! is_array( $source_request ) || true !== ( $input['confirm_merge'] ?? false ) ) {
			throw new Execution_Exception( 'localized_group_merge_confirmation_required', 'An exact Blueprint, target and source group snapshots, and explicit merge confirmation are required.' );
		}

		$blueprint = $this->config->get_blueprint( $page_type );
		$post_type = sanitize_key( (string) ( $blueprint['target_post_type'] ?? '' ) );
		$target = $this->group_snapshot( $target_request, $page_type, $post_type, 'target' );
		$source = $this->group_snapshot( $source_request, $page_type, $post_type, 'source' );
		$expected_union = $this->merge_translation_maps( $target['translations'], $source['translations'] );
		if ( $target['anchor_post_id'] === $source['anchor_post_id'] ) {
			throw new Execution_Exception( 'localized_group_merge_same_group', 'The target and source anchors must identify different translation groups.' );
		}
		$replay = $this->merged_group_replay( $target, $source, $expected_union, $post_type );
		if ( null !== $replay ) {
			return $this->group_merge_result( $page_type, $post_type, $replay, true );
		}
		$this->assert_group_snapshot_current( $target, 'target' );
		$this->assert_group_snapshot_current( $source, 'source' );

		$lock_ids = array_values( array_unique( array_merge( array_values( $target['translations'] ), array_values( $source['translations'] ) ) ) );
		$locks = $this->acquire_locks( $lock_ids );
		try {
			$target = $this->group_snapshot( $target_request, $page_type, $post_type, 'target' );
			$source = $this->group_snapshot( $source_request, $page_type, $post_type, 'source' );
			$expected_union = $this->merge_translation_maps( $target['translations'], $source['translations'] );
			$replay = $this->merged_group_replay( $target, $source, $expected_union, $post_type );
			if ( null !== $replay ) {
				return $this->group_merge_result( $page_type, $post_type, $replay, true );
			}
			$this->assert_group_snapshot_current( $target, 'target' );
			$this->assert_group_snapshot_current( $source, 'source' );
			$result = $this->localization->merge_translation_groups( $page_type, $post_type, $target, $source );
			$confirmed = $this->localization->resolve( $target['anchor_post_id'], $post_type );
			if ( ! $this->same_translation_map( $expected_union, (array) ( $confirmed['translations'] ?? array() ) ) ) {
				throw new Execution_Exception( 'localization_group_merge_failed', 'The provider did not confirm the exact merged translation group.' );
			}
			$result['page_type'] = $page_type;
			$result['post_type'] = $post_type;
			$result['localization_group'] = (string) $confirmed['localization_group'];
			$result['translations'] = $expected_union;
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

	private function inspect_editable_content( int $post_id, string $page_type, string $post_type ): array {
		$inspected = $this->drafts->inspect_content_item( array( 'post_id' => $post_id, 'page_type' => $page_type ) );
		if ( $post_type !== sanitize_key( (string) ( $inspected['post_type'] ?? '' ) )
			|| ! in_array( (string) ( $inspected['status'] ?? '' ), array( 'draft', 'publish' ), true ) ) {
			throw new Execution_Exception( 'localized_content_contract_mismatch', 'Every relationship member must match the Blueprint post type and be a draft or published item.' );
		}
		if ( '' !== sanitize_key( (string) get_post_meta( $post_id, Content_Proposal_Service::STATE_META, true ) ) ) {
			throw new Execution_Exception( 'localized_content_proposal_forbidden', 'Published-content proposal working copies cannot be moved between translation groups.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new Execution_Exception( 'localized_content_edit_forbidden', 'The current user cannot change this content relationship.' );
		}
		return $inspected;
	}

	private function content_candidate( array $requested, string $page_type, string $post_type ): array {
		$post_id = absint( $requested['post_id'] ?? 0 );
		if ( $post_id < 1 ) {
			throw new Execution_Exception( 'localized_content_item_invalid', 'The localized content ID must be positive.' );
		}
		$inspected = $this->inspect_editable_content( $post_id, $page_type, $post_type );
		$expected_modified = trim( (string) ( $requested['expected_modified_gmt'] ?? '' ) );
		$expected_hash = strtolower( trim( (string) ( $requested['expected_content_hash'] ?? '' ) ) );
		if ( ! hash_equals( $expected_modified, (string) ( $inspected['modified_gmt'] ?? '' ) )
			|| ! hash_equals( $expected_hash, (string) ( $inspected['content_hash'] ?? '' ) ) ) {
			throw new Execution_Exception( 'edit_conflict', 'The localized content item changed after it was inspected.' );
		}
		$context = (array) ( $inspected['localization'] ?? array() );
		$content_language = trim( (string) ( $context['content_language'] ?? '' ) );
		$submitted_language = trim( (string) ( $requested['content_language'] ?? '' ) );
		$language_code = sanitize_key( (string) ( $context['language_code'] ?? '' ) );
		$provider = sanitize_key( (string) ( $context['provider'] ?? '' ) );
		if ( '' === $content_language || 0 !== strcasecmp( $content_language, $submitted_language ) || '' === $language_code || '' === $provider || 'wordpress' === $provider ) {
			throw new Execution_Exception( 'localized_content_language_conflict', 'The requested language must match the current provider language assigned to the content item.' );
		}
		return array(
			'post_id' => $post_id,
			'status' => (string) $inspected['status'],
			'content_language' => $content_language,
			'language_code' => $language_code,
			'localization_provider' => $provider,
			'localization_group' => (string) ( $context['localization_group'] ?? '' ),
		);
	}

	private function assert_content_attach_context( array $context, array $candidate, string $expected_group ): bool {
		if ( (string) ( $context['provider'] ?? '' ) !== (string) $candidate['localization_provider'] ) {
			throw new Execution_Exception( 'localized_content_provider_conflict', 'The translation group and content item must use the same localization provider.' );
		}
		$language_code = (string) $candidate['language_code'];
		$occupied_post_id = absint( ( $context['translations'] ?? array() )[ $language_code ] ?? 0 );
		if ( $occupied_post_id === $candidate['post_id'] ) {
			return true;
		}
		if ( $occupied_post_id > 0 ) {
			throw new Execution_Exception( 'localized_content_language_slot_occupied', 'The requested translation group already contains another item in this language.' );
		}
		if ( ! hash_equals( $expected_group, (string) ( $context['localization_group'] ?? '' ) ) ) {
			throw new Execution_Exception( 'localized_content_group_conflict', 'The target translation group changed after it was inspected.' );
		}
		return false;
	}

	private function assert_singleton_source_group( array $context, array $candidate ): void {
		$translations = $this->normalize_translation_map( (array) ( $context['translations'] ?? array() ) );
		if ( (string) ( $context['provider'] ?? '' ) !== (string) $candidate['localization_provider']
			|| 1 !== count( $translations )
			|| absint( $translations[ (string) $candidate['language_code'] ] ?? 0 ) !== $candidate['post_id'] ) {
			throw new Execution_Exception( 'localized_content_source_group_not_singleton', 'Attach can move only an unlinked content item. Use the group merge operation for a multi-item source group.' );
		}
	}

	private function content_attachment_result( string $page_type, string $post_type, array $context, array $candidate, bool $idempotent ): array {
		return array(
			'provider' => (string) $context['provider'],
			'page_type' => $page_type,
			'post_type' => $post_type,
			'localization_group' => (string) $context['localization_group'],
			'translations' => $this->normalize_translation_map( (array) $context['translations'] ),
			'item' => $candidate,
			'idempotent_replay' => $idempotent,
		);
	}

	private function group_snapshot( array $requested, string $page_type, string $post_type, string $role ): array {
		$anchor_post_id = absint( $requested['anchor_post_id'] ?? 0 );
		$expected_group = substr( sanitize_text_field( (string) ( $requested['expected_localization_group'] ?? '' ) ), 0, 128 );
		$translations = $this->translation_list( $requested['translations'] ?? null, $role );
		if ( $anchor_post_id < 1 || '' === $expected_group || ! in_array( $anchor_post_id, array_values( $translations ), true ) ) {
			throw new Execution_Exception( 'localized_group_snapshot_invalid', 'Each group snapshot must identify an anchor, exact provider group, and complete translations containing that anchor.' );
		}
		foreach ( $translations as $language_code => $post_id ) {
			$inspected = $this->inspect_editable_content( $post_id, $page_type, $post_type );
			$context = (array) ( $inspected['localization'] ?? array() );
			if ( $language_code !== sanitize_key( (string) ( $context['language_code'] ?? '' ) ) ) {
				throw new Execution_Exception( 'localized_group_language_conflict', 'A requested group member does not match its current provider language.' );
			}
		}
		$current = $this->localization->resolve( $anchor_post_id, $post_type );
		return array(
			'anchor_post_id' => $anchor_post_id,
			'expected_localization_group' => $expected_group,
			'translations' => $translations,
			'current_localization_group' => (string) ( $current['localization_group'] ?? '' ),
			'current_translations' => $this->normalize_translation_map( (array) ( $current['translations'] ?? array() ) ),
			'provider' => sanitize_key( (string) ( $current['provider'] ?? '' ) ),
		);
	}

	private function translation_list( mixed $requested, string $role ): array {
		if ( ! is_array( $requested ) || ! array_is_list( $requested ) || empty( $requested ) || count( $requested ) > 20 ) {
			throw new Execution_Exception( 'localized_group_snapshot_invalid', 'The ' . $role . ' group must provide a non-empty exact translation list.' );
		}
		$translations = array();
		foreach ( $requested as $item ) {
			$language_code = is_array( $item ) ? sanitize_key( (string) ( $item['language_code'] ?? '' ) ) : '';
			$post_id = is_array( $item ) ? absint( $item['post_id'] ?? 0 ) : 0;
			if ( '' === $language_code || $post_id < 1 || isset( $translations[ $language_code ] ) || in_array( $post_id, $translations, true ) ) {
				throw new Execution_Exception( 'localized_group_snapshot_invalid', 'Translation language codes and content IDs must be positive and unique within each group.' );
			}
			$translations[ $language_code ] = $post_id;
		}
		ksort( $translations );
		return $translations;
	}

	private function merge_translation_maps( array $target, array $source ): array {
		$merged = $target;
		foreach ( $source as $language_code => $post_id ) {
			if ( isset( $merged[ $language_code ] ) && $merged[ $language_code ] !== $post_id ) {
				throw new Execution_Exception( 'localized_group_language_slot_conflict', 'The two translation groups contain different items for the same language.' );
			}
			if ( in_array( $post_id, $merged, true ) && ( $merged[ $language_code ] ?? 0 ) !== $post_id ) {
				throw new Execution_Exception( 'localized_group_member_conflict', 'One content item cannot occupy two language slots.' );
			}
			$merged[ $language_code ] = $post_id;
		}
		ksort( $merged );
		return $merged;
	}

	private function merged_group_replay( array $target, array $source, array $expected_union, string $post_type ): ?array {
		$target_context = $this->localization->resolve( $target['anchor_post_id'], $post_type );
		$source_context = $this->localization->resolve( $source['anchor_post_id'], $post_type );
		if ( (string) ( $target_context['provider'] ?? '' ) !== (string) ( $source_context['provider'] ?? '' ) ) {
			throw new Execution_Exception( 'localized_group_provider_conflict', 'Both translation groups must use the same localization provider.' );
		}
		if ( hash_equals( (string) ( $target_context['localization_group'] ?? '' ), (string) ( $source_context['localization_group'] ?? '' ) )
			&& $this->same_translation_map( $expected_union, (array) ( $target_context['translations'] ?? array() ) )
			&& $this->same_translation_map( $expected_union, (array) ( $source_context['translations'] ?? array() ) ) ) {
			return $target_context;
		}
		return null;
	}

	private function assert_group_snapshot_current( array $snapshot, string $role ): void {
		if ( ! hash_equals( (string) $snapshot['expected_localization_group'], (string) $snapshot['current_localization_group'] )
			|| ! $this->same_translation_map( $snapshot['translations'], $snapshot['current_translations'] ) ) {
			throw new Execution_Exception( 'localized_group_snapshot_conflict', 'The ' . $role . ' translation group changed after it was inspected.' );
		}
	}

	private function normalize_translation_map( array $translations ): array {
		$normalized = array();
		foreach ( $translations as $language_code => $post_id ) {
			$language_code = sanitize_key( (string) $language_code );
			$post_id = absint( $post_id );
			if ( '' !== $language_code && $post_id > 0 ) {
				$normalized[ $language_code ] = $post_id;
			}
		}
		ksort( $normalized );
		return $normalized;
	}

	private function same_translation_map( array $expected, array $actual ): bool {
		return $this->normalize_translation_map( $expected ) === $this->normalize_translation_map( $actual );
	}

	private function group_merge_result( string $page_type, string $post_type, array $context, bool $idempotent ): array {
		return array(
			'provider' => (string) $context['provider'],
			'page_type' => $page_type,
			'post_type' => $post_type,
			'localization_group' => (string) $context['localization_group'],
			'translations' => $this->normalize_translation_map( (array) $context['translations'] ),
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
