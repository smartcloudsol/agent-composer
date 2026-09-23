<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

/** Read Structure Contracts and assigned drafts through stable semantic identities. */
final class Semantic_Document_Service {
	public function __construct(
		private Config_Repository $config,
		private Draft_Service $drafts,
		private Page_Validator $validator,
		private Ability_Provider_Registry $providers,
		private Synced_Structural_Pattern_Service $synced_patterns
	) {}

	public function get_contract( array $input ): array {
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$blueprint = $this->config->get_blueprint( $page_type );
		$contract  = $this->enforced_contract( $blueprint );

		return array(
			'page_type'               => $page_type,
			'blueprint_version'        => (int) ( $blueprint['schema_version'] ?? 0 ),
			'structure_contract_mode' => 'enforced',
			'contract'                 => $contract,
			'field_ids'                => array_values(
				array_map(
					static fn( array $node ): string => (string) $node['id'],
					array_filter(
						(array) $contract['nodes'],
						static fn( array $node ): bool => 'INSTANCE_CONTENT' === (string) ( $node['ownership'] ?? '' )
					)
				)
			),
			'media_field_ids'          => array_values(
				array_map(
					static fn( array $node ): string => (string) $node['id'],
					array_filter(
						(array) $contract['nodes'],
						static fn( array $node ): bool => 'INSTANCE_CONTENT' === (string) ( $node['ownership'] ?? '' )
							&& 'core/image' === (string) ( $node['block'] ?? '' )
					)
				)
			),
			'slot_ids'                 => array_values(
				array_map(
					static fn( array $node ): string => (string) $node['id'],
					array_filter(
						(array) $contract['nodes'],
						static fn( array $node ): bool => 'slot' === (string) ( $node['mode'] ?? '' )
					)
				)
			),
			'semantic_operations'      => array(
				'get_document' => Abilities::PREFIX . 'get-document',
				'set_field'    => Abilities::PREFIX . 'set-field',
				'replace_media' => Abilities::PREFIX . 'replace-media',
				'insert_slot_block' => Abilities::PREFIX . 'insert-slot-block',
				'update_slot_block' => Abilities::PREFIX . 'update-slot-block',
				'move_slot_block' => Abilities::PREFIX . 'move-slot-block',
				'remove_slot_block' => Abilities::PREFIX . 'remove-slot-block',
				'validate_proposal' => Abilities::PREFIX . 'validate-proposal',
			),
			'raw_post_content_required' => false,
		);
	}

	public function insert_slot_block( array $input ): array {
		$this->assert_slot_confirmation( $input, 'insert' );
		$context = $this->slot_context( $input );
		$blocks  = $this->materialize_slot_spec( (array) ( $input['block'] ?? array() ), $context['blueprint'] );
		$block   = $this->mark_user_tree( $blocks[0], $context['contract'], $context['slot_id'] );
		$children = $context['children'];
		$index    = $this->slot_insertion_index( $children, (string) ( $input['position'] ?? 'end' ), (string) ( $input['reference_user_block_id'] ?? '' ) );
		array_splice( $children, $index, 0, array( $block ) );

		$result = $this->save_slot_children( $input, $context, $children );
		$result['semantic_mutation'] = array(
			'operation'     => 'insert_slot_block',
			'slot'          => $context['slot_id'],
			'user_block_id' => $this->user_block_id( $block ),
			'position'      => (string) ( $input['position'] ?? 'end' ),
		);
		return $result;
	}

	public function update_slot_block( array $input ): array {
		$this->assert_slot_confirmation( $input, 'update' );
		$context = $this->slot_context( $input );
		$user_id = $this->valid_user_block_id( (string) ( $input['user_block_id'] ?? '' ) );
		$matches = $this->find_user_occurrences( $context['children'], $user_id, array() );
		if ( 1 !== count( $matches ) ) {
			throw new Execution_Exception( 'semantic_slot_block_ambiguous', 'The requested user-owned block must resolve exactly once in the selected extension slot.' );
		}

		$blocks      = $this->materialize_slot_spec( (array) ( $input['block'] ?? array() ), $context['blueprint'] );
		$replacement = $this->mark_user_tree( $blocks[0], $context['contract'], $context['slot_id'], $user_id );
		$children    = $context['children'];
		$this->replace_slot_child_at_path( $children, $matches[0]['path'], array( $replacement ) );
		$result = $this->save_slot_children( $input, $context, $children );
		$result['semantic_mutation'] = array( 'operation' => 'update_slot_block', 'slot' => $context['slot_id'], 'user_block_id' => $user_id );
		return $result;
	}

	public function move_slot_block( array $input ): array {
		$this->assert_slot_confirmation( $input, 'move' );
		$context = $this->slot_context( $input );
		$user_id = $this->valid_user_block_id( (string) ( $input['user_block_id'] ?? '' ) );
		$children = $context['children'];
		$source   = $this->direct_user_index( $children, $user_id );
		if ( null === $source ) {
			throw new Execution_Exception( 'semantic_slot_block_not_direct', 'Only a direct extension-slot child can be reordered.' );
		}
		$block = $children[ $source ];
		array_splice( $children, $source, 1 );
		$position  = (string) ( $input['position'] ?? 'end' );
		$reference = (string) ( $input['reference_user_block_id'] ?? '' );
		if ( '' !== $reference && hash_equals( $user_id, $reference ) ) {
			throw new Execution_Exception( 'semantic_slot_move_reference_invalid', 'A slot block cannot be positioned relative to itself.' );
		}
		$index = $this->slot_insertion_index( $children, $position, $reference );
		array_splice( $children, $index, 0, array( $block ) );

		$result = $this->save_slot_children( $input, $context, $children );
		$result['semantic_mutation'] = array( 'operation' => 'move_slot_block', 'slot' => $context['slot_id'], 'user_block_id' => $user_id, 'position' => $position );
		return $result;
	}

	public function remove_slot_block( array $input ): array {
		$this->assert_slot_confirmation( $input, 'remove' );
		$context = $this->slot_context( $input );
		$user_id = $this->valid_user_block_id( (string) ( $input['user_block_id'] ?? '' ) );
		$matches = $this->find_user_occurrences( $context['children'], $user_id, array() );
		if ( 1 !== count( $matches ) ) {
			throw new Execution_Exception( 'semantic_slot_block_ambiguous', 'The requested user-owned block must resolve exactly once in the selected extension slot.' );
		}
		$children = $context['children'];
		$this->replace_slot_child_at_path( $children, $matches[0]['path'], array() );
		$result = $this->save_slot_children( $input, $context, $children );
		$result['semantic_mutation'] = array( 'operation' => 'remove_slot_block', 'slot' => $context['slot_id'], 'user_block_id' => $user_id );
		return $result;
	}

	public function set_field( array $input ): array {
		if ( true !== ( $input['confirm_update'] ?? false ) ) {
			throw new Execution_Exception( 'semantic_field_confirmation_required', 'Semantic field updates require confirm_update=true.' );
		}
		$post_id    = absint( $input['post_id'] ?? 0 );
		$field_id   = $this->field_id_from_input( $input );
		$pattern_instance_id = $this->pattern_instance_id_from_input( $input );
		$post       = $this->drafts->get_owned_draft( $post_id );
		$page_type  = sanitize_key( (string) get_post_meta( $post_id, Draft_Service::PAGE_TYPE_META, true ) );
		$blueprint  = $this->config->get_blueprint( $page_type );
		$contract   = $this->enforced_contract( $blueprint );
		$definitions = array_column( (array) $contract['nodes'], null, 'id' );
		$definition = $definitions[ $field_id ] ?? null;
		if ( ! is_array( $definition ) || 'INSTANCE_CONTENT' !== (string) ( $definition['ownership'] ?? '' ) ) {
			throw new Execution_Exception( 'semantic_field_not_editable', 'The requested semantic field is not an instance-content field in the active Structure Contract.' );
		}

		$blocks      = parse_blocks( (string) $post->post_content );
		$occurrences = $this->find_node_occurrences( $blocks, $definitions, $field_id );
		$override_owners = array();
		if ( empty( $occurrences ) ) {
			$override_owners = $this->synced_patterns->find_override_owners( $blocks, $blueprint, $field_id, $pattern_instance_id );
		}
		if ( '' !== $pattern_instance_id && ! empty( $occurrences ) ) {
			throw new Execution_Exception( 'pattern_instance_unexpected', 'pattern_instance_id may be used only for a synced pattern override field.' );
		}
		if ( '' === $pattern_instance_id && count( $override_owners ) > 1 ) {
			throw new Execution_Exception( 'pattern_instance_required', 'Choose the synced pattern override with its pattern_instance_id and field_id.' );
		}
		if ( 1 !== count( $occurrences ) && 1 !== count( $override_owners ) ) {
			throw new Execution_Exception( 'semantic_field_ambiguous', 'The requested semantic field must resolve to exactly one block before it can be updated.' );
		}

		$block     = 1 === count( $occurrences ) ? $occurrences[0]['block'] : array();
		$attribute = trim( (string) ( $input['attribute'] ?? '' ) );
		$editable  = array_values( (array) ( $definition['editable_attributes'] ?? array() ) );
		$set_content = '' === $attribute && true === ( $definition['editable_content'] ?? false );
		if ( 'content' === $attribute && true === ( $definition['editable_content'] ?? false ) ) {
			$set_content = true;
		}
		if ( ! $set_content && '' === $attribute && 1 === count( $editable ) ) {
			$attribute = (string) $editable[0];
		}

		if ( $set_content ) {
			if ( ! is_string( $input['value'] ?? null ) ) {
				throw new Execution_Exception( 'semantic_field_value_invalid', 'Editable rich-text content requires a string value.' );
			}
			if ( 1 === count( $occurrences ) ) {
				$block = $this->replace_block_content( $block, (string) $input['value'] );
			}
			$attribute = 'content';
		} else {
			if ( '' === $attribute || ! in_array( $attribute, $editable, true ) || $this->reserved_attribute( $attribute ) ) {
				throw new Execution_Exception( 'semantic_field_attribute_not_editable', 'The requested block attribute is not editable through this semantic field.' );
			}
			$this->assert_attribute_value( (string) ( $definition['block'] ?? '' ), $attribute, $input['value'] ?? null );
			if ( 1 === count( $occurrences ) ) {
				$block['attrs']               = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$block['attrs'][ $attribute ] = $input['value'] ?? null;
			}
		}
		$path = 1 === count( $occurrences ) ? $occurrences[0]['path'] : $override_owners[0]['path'];
		if ( 1 === count( $override_owners ) ) {
			$allowed = (array) ( $override_owners[0]['definition']['attributes'] ?? array() );
			if ( ! in_array( $attribute, $allowed, true ) ) {
				throw new Execution_Exception( 'pattern_override_attribute_forbidden', 'The synced pattern does not expose this semantic field attribute as a Pattern Override.' );
			}
			$block = $this->synced_patterns->with_override( $override_owners[0]['block'], $field_id, $attribute, $input['value'] ?? null, (string) $override_owners[0]['pattern_instance_id'] );
		}

		$result = $this->drafts->insert_or_update_blocks(
			array(
				'post_id'               => $post_id,
				'expected_modified_gmt' => (string) ( $input['expected_modified_gmt'] ?? '' ),
				'expected_revision'     => (string) ( $input['expected_revision'] ?? '' ),
				'mode'                  => 'replace',
				'path'                  => $path,
				'blocks'                => array( $block ),
			)
		);
		$result['semantic_mutation'] = array(
			'operation' => 'set_field',
			'field'     => $field_id,
			'attribute' => $attribute,
		);
		if ( 1 === count( $override_owners ) ) {
			$result['semantic_mutation']['pattern_instance_id'] = (string) $override_owners[0]['pattern_instance_id'];
		}
		return $result;
	}

	public function replace_media( array $input ): array {
		if ( true !== ( $input['confirm_update'] ?? false ) ) {
			throw new Execution_Exception( 'semantic_media_confirmation_required', 'Semantic media replacement requires confirm_update=true.' );
		}
		$post_id       = absint( $input['post_id'] ?? 0 );
		$field_id      = $this->field_id_from_input( $input );
		$pattern_instance_id = $this->pattern_instance_id_from_input( $input );
		$attachment_id = absint( $input['attachment_id'] ?? 0 );
		$post          = $this->drafts->get_owned_draft( $post_id );
		$page_type     = sanitize_key( (string) get_post_meta( $post_id, Draft_Service::PAGE_TYPE_META, true ) );
		$blueprint     = $this->config->get_blueprint( $page_type );
		$contract      = $this->enforced_contract( $blueprint );
		$definitions = array_column( (array) $contract['nodes'], null, 'id' );
		$definition  = $definitions[ $field_id ] ?? null;
		if (
			! is_array( $definition )
			|| 'INSTANCE_CONTENT' !== (string) ( $definition['ownership'] ?? '' )
			|| 'core/image' !== (string) ( $definition['block'] ?? '' )
			|| ! in_array( 'id', (array) ( $definition['editable_attributes'] ?? array() ), true )
			|| true !== ( $definition['editable_content'] ?? false )
		) {
			throw new Execution_Exception( 'semantic_media_field_not_editable', 'The requested semantic field is not an editable core/image media field in the active Structure Contract.' );
		}

		$raw_blocks = parse_blocks( (string) $post->post_content );
		$occurrences = $this->find_node_occurrences( $raw_blocks, $definitions, $field_id );
		$override_owners = array();
		if ( empty( $occurrences ) ) {
			$override_owners = $this->synced_patterns->find_override_owners( $raw_blocks, $blueprint, $field_id, $pattern_instance_id );
			$expanded = $this->synced_patterns->expand_blocks( $raw_blocks, $blueprint );
			$occurrences = $this->find_node_occurrences( $expanded, $definitions, $field_id, array(), $pattern_instance_id );
		}
		if ( '' !== $pattern_instance_id && empty( $override_owners ) ) {
			throw new Execution_Exception( 'pattern_override_not_found', 'The pattern_instance_id and field_id pair does not identify an editable synced pattern field.' );
		}
		if ( '' === $pattern_instance_id && count( $override_owners ) > 1 ) {
			throw new Execution_Exception( 'pattern_instance_required', 'Choose the synced pattern media override with its pattern_instance_id and field_id.' );
		}
		if ( 1 !== count( $occurrences ) || ( ! empty( $override_owners ) && 1 !== count( $override_owners ) ) || 'core/image' !== (string) ( $occurrences[0]['block']['blockName'] ?? '' ) ) {
			throw new Execution_Exception( 'semantic_media_field_ambiguous', 'The requested semantic media field must resolve to exactly one core/image block.' );
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $attachment_id ) ) {
			throw new Execution_Exception( 'image_attachment_not_found', 'The requested Media Library image does not exist.' );
		}
		if ( ! current_user_can( 'read_post', $attachment_id ) ) {
			throw new Execution_Exception( 'image_attachment_read_denied', 'The current agent cannot read this Media Library image.' );
		}

		$block       = $occurrences[0]['block'];
		$attrs       = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$existing_size_slug = $this->image_size_slug( $block );
		$size_slug   = sanitize_key( (string) ( $input['size_slug'] ?? $existing_size_slug ) );
		if ( '' === $size_slug ) {
			$size_slug = 'full';
		}
		if (
			array_key_exists( 'size_slug', $input )
			&& $size_slug !== $existing_size_slug
			&& ! in_array( 'sizeSlug', (array) ( $definition['editable_attributes'] ?? array() ), true )
		) {
			throw new Execution_Exception( 'semantic_media_size_not_editable', 'The Structure Contract does not allow this semantic media field to change image size.' );
		}
		if ( array_key_exists( 'size_slug', $input ) && $size_slug !== $existing_size_slug ) {
			$block['attrs']             = $attrs;
			$block['attrs']['sizeSlug'] = $size_slug;
		}
		$sizes = array_values( array_unique( array_merge( get_intermediate_image_sizes(), array( 'full' ) ) ) );
		if ( ! in_array( $size_slug, $sizes, true ) ) {
			throw new Execution_Exception( 'image_size_not_available', 'The requested image size is not registered on this WordPress site.' );
		}
		$image_source = wp_get_attachment_image_src( $attachment_id, $size_slug );
		if ( ! is_array( $image_source ) || empty( $image_source[0] ) ) {
			throw new Execution_Exception( 'image_markup_unavailable', 'WordPress could not resolve the requested image source.' );
		}
		$media_url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $media_url ) || '' === $media_url ) {
			throw new Execution_Exception( 'image_markup_unavailable', 'WordPress could not resolve the full Media Library image URL.' );
		}
		$alt   = wp_strip_all_tags( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		$title = wp_strip_all_tags( (string) $attachment->post_title );
		$block = $this->replace_image_block(
			$block,
			$attachment_id,
			$size_slug,
			(string) $image_source[0],
			$media_url,
			$alt,
			$title
		);

		$save_block = $block;
		$save_path  = $occurrences[0]['path'];
		if ( 1 === count( $override_owners ) ) {
			$allowed    = (array) ( $override_owners[0]['definition']['attributes'] ?? array() );
			$projection = $this->image_projection( $block );
			$values     = array(
				'id'      => $attachment_id,
				'url'     => (string) $image_source[0],
				'alt'     => $alt,
				'title'   => $title,
				'caption' => (string) ( $projection['caption'] ?? '' ),
			);
			$save_block = $override_owners[0]['block'];
			foreach ( $values as $attribute => $value ) {
				if ( in_array( $attribute, $allowed, true ) ) {
					$save_block = $this->synced_patterns->with_override( $save_block, $field_id, $attribute, $value, (string) $override_owners[0]['pattern_instance_id'] );
				}
			}
			if ( ! in_array( 'id', $allowed, true ) || ! in_array( 'url', $allowed, true ) || ! in_array( 'alt', $allowed, true ) ) {
				throw new Execution_Exception( 'pattern_override_media_incomplete', 'A synced core/image field must expose id, url, and alt Pattern Override attributes.' );
			}
			$save_path = $override_owners[0]['path'];
		}
		$result = $this->drafts->insert_or_update_blocks(
			array(
				'post_id'               => $post_id,
				'expected_modified_gmt' => (string) ( $input['expected_modified_gmt'] ?? '' ),
				'expected_revision'     => (string) ( $input['expected_revision'] ?? '' ),
				'mode'                  => 'replace',
				'path'                  => $save_path,
				'blocks'                => array( $save_block ),
			)
		);
		$result['semantic_mutation'] = array(
			'operation'     => 'replace_media',
			'field'         => $field_id,
			'attachment_id' => $attachment_id,
			'size_slug'     => $size_slug,
			'alt'           => $alt,
			'width'         => absint( $image_source[1] ?? 0 ),
			'height'        => absint( $image_source[2] ?? 0 ),
		);
		if ( 1 === count( $override_owners ) ) {
			$result['semantic_mutation']['pattern_instance_id'] = (string) $override_owners[0]['pattern_instance_id'];
		}
		return $result;
	}

	public function get_document( array $input ): array {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$descriptor = $this->drafts->get( $post_id );
		$post       = $this->drafts->get_owned_draft( $post_id );
		$revision   = (string) get_post_meta( $post_id, Draft_Service::REVISION_META, true );
		if ( ! hash_equals( (string) ( $descriptor['revision'] ?? '' ), $revision ) ) {
			throw new Execution_Exception( 'document_read_conflict', 'The draft changed while its semantic document was being read. Fetch it again.' );
		}

		$page_type = sanitize_key( (string) ( $descriptor['page_type'] ?? '' ) );
		$blueprint = $this->config->get_blueprint( $page_type );
		$contract  = $this->enforced_contract( $blueprint );
		$content   = (string) $post->post_content;
		$raw_blocks = parse_blocks( $content );
		$seen_pattern_instances = array();
		$pattern_instances = $this->synced_patterns->inventory( $raw_blocks, $blueprint, array(), null, $seen_pattern_instances );
		$document_blocks = $this->synced_patterns->expand_blocks( $raw_blocks, $blueprint );
		$document  = $this->project_document( $contract, $document_blocks );
		$document['pattern_instances'] = array_values(
			array_map(
				static function ( array $instance ): array {
					unset( $instance['path'] );
					return $instance;
				},
				$pattern_instances
			)
		);

		return array(
			'post_id'          => $post_id,
			'title'            => (string) ( $descriptor['title'] ?? '' ),
			'page_type'        => $page_type,
			'modified_gmt'     => (string) ( $descriptor['modified_gmt'] ?? '' ),
			'revision'         => $revision,
			'proposal_state'   => (string) ( $descriptor['proposal_state'] ?? '' ),
			'managed_document' => (array) ( $descriptor['managed_document'] ?? array() ),
			'contract'         => array(
				'id'      => (string) $contract['id'],
				'version' => (int) $contract['version'],
			),
			'document'         => $document,
			'validation'       => $this->validator->validate( $page_type, $content ),
			'raw_post_content_included' => false,
		);
	}

	private function assert_slot_confirmation( array $input, string $operation ): void {
		$operation = sanitize_key( $operation );
		if ( true !== ( $input['confirm_update'] ?? false ) ) {
			throw new Execution_Exception( 'semantic_slot_confirmation_required', 'A semantic slot change requires confirm_update=true.' );
		}
	}

	private function slot_context( array $input ): array {
		$post_id   = absint( $input['post_id'] ?? 0 );
		$slot_id   = trim( (string) ( $input['slot'] ?? '' ) );
		$post      = $this->drafts->get_owned_draft( $post_id );
		$page_type = sanitize_key( (string) get_post_meta( $post_id, Draft_Service::PAGE_TYPE_META, true ) );
		$blueprint = $this->config->get_blueprint( $page_type );
		$contract  = $this->enforced_contract( $blueprint );
		$nodes     = array_column( (array) $contract['nodes'], null, 'id' );
		$slot      = $nodes[ $slot_id ] ?? null;
		if ( ! is_array( $slot ) || 'slot' !== (string) ( $slot['mode'] ?? '' ) ) {
			throw new Execution_Exception( 'semantic_slot_not_found', 'The requested semantic extension slot is not declared by the active Structure Contract.' );
		}
		$pattern_instance_id = $this->pattern_instance_id_from_input( $input );
		$raw_blocks = parse_blocks( (string) $post->post_content );
		$occurrences = $this->find_node_occurrences( $raw_blocks, $nodes, $slot_id );
		if ( '' !== $pattern_instance_id && ! empty( $occurrences ) ) {
			throw new Execution_Exception( 'pattern_instance_unexpected', 'pattern_instance_id may be used only for a slot inside a synced pattern.' );
		}
		if ( 1 === count( $occurrences ) ) {
			$children = isset( $occurrences[0]['block']['innerBlocks'] ) && is_array( $occurrences[0]['block']['innerBlocks'] )
				? array_values( $occurrences[0]['block']['innerBlocks'] )
				: array();
			return array(
				'post_id'    => $post_id,
				'page_type'  => $page_type,
				'blueprint'  => $blueprint,
				'contract'   => $contract,
				'slot_id'    => $slot_id,
				'definition' => $slot,
				'block'      => $occurrences[0]['block'],
				'path'       => $occurrences[0]['path'],
				'children'   => $children,
				'storage'    => 'direct',
			);
		}

		$owners = $this->synced_patterns->find_slot_owners( $raw_blocks, $blueprint, $slot_id, $pattern_instance_id );
		if ( '' === $pattern_instance_id && count( $owners ) > 1 ) {
			throw new Execution_Exception( 'pattern_instance_required', 'Choose the synced pattern slot with its pattern_instance_id and slot ID.' );
		}
		if ( 1 !== count( $owners ) ) {
			throw new Execution_Exception( 'semantic_slot_ambiguous', 'The requested semantic extension slot must resolve exactly once.' );
		}
		return array(
			'post_id'    => $post_id,
			'page_type'  => $page_type,
			'blueprint'  => $blueprint,
			'contract'   => $contract,
			'slot_id'    => $slot_id,
			'definition' => $slot,
			'block'      => $owners[0]['block'],
			'path'       => $owners[0]['path'],
			'children'   => $owners[0]['children'],
			'pattern_instance_id' => $owners[0]['pattern_instance_id'],
			'storage'    => 'synced',
		);
	}

	private function materialize_slot_spec( array $spec, array $blueprint ): array {
		$pattern = strtolower( trim( (string) ( $spec['pattern'] ?? '' ) ) );
		if ( '' !== $pattern ) {
			$allowed = array( 'pattern', 'pattern_instance_id', 'fields' );
			if ( array_diff( array_keys( $spec ), $allowed ) || ! is_array( $spec['fields'] ?? null ) ) {
				throw new Execution_Exception( 'semantic_slot_pattern_invalid', 'A synced pattern slot item accepts only pattern, pattern_instance_id, and an object fields map.' );
			}
			$materialized = $this->synced_patterns->materialize_instance(
				$pattern,
				$spec['fields'],
				$blueprint,
				false,
				$this->pattern_instance_id_from_input( $spec )
			);
			return array( $materialized['block'] );
		}

		$provider = sanitize_key( (string) ( $spec['provider'] ?? '' ) );
		if ( '' !== $provider ) {
			$allowed = array( 'provider', 'component', 'spec' );
			if ( array_diff( array_keys( $spec ), $allowed ) || ! is_array( $spec['spec'] ?? null ) ) {
				throw new Execution_Exception( 'semantic_slot_component_invalid', 'A provider slot block accepts only provider, component, and an object spec.' );
			}
			return $this->providers->materialize_component( $provider, (string) ( $spec['component'] ?? '' ), $spec['spec'] );
		}

		$allowed = array( 'name', 'attributes', 'content', 'attachment_id', 'size_slug' );
		if ( array_diff( array_keys( $spec ), $allowed ) ) {
			throw new Execution_Exception( 'semantic_slot_block_property_invalid', 'A core semantic slot block contains an unsupported property.' );
		}
		$name  = strtolower( trim( (string) ( $spec['name'] ?? '' ) ) );
		$attrs = isset( $spec['attributes'] ) && is_array( $spec['attributes'] ) ? $spec['attributes'] : array();
		foreach ( array( 'metadata', 'composer', 'lock', 'templateLock', 'slotId', 'allowedBlocks', 'allowedPatterns', 'patternOccurrences', 'minBlocks', 'maxBlocks' ) as $reserved ) {
			if ( array_key_exists( $reserved, $attrs ) ) {
				throw new Execution_Exception( 'semantic_slot_reserved_attribute', 'Composer ownership and editor-control attributes cannot be supplied in a semantic slot block.' );
			}
		}
		$content = is_string( $spec['content'] ?? null ) ? wp_kses_post( (string) $spec['content'] ) : '';
		return array( $this->materialize_core_block( $name, $attrs, $content, $spec ) );
	}

	private function materialize_core_block( string $name, array $attrs, string $content, array $spec ): array {
		if ( 'core/paragraph' === $name ) {
			return $this->block_from_markup( $name, $attrs, '<p>' . $content . '</p>' );
		}
		if ( 'core/heading' === $name ) {
			$level = isset( $attrs['level'] ) ? (int) $attrs['level'] : 2;
			if ( $level < 1 || $level > 6 ) {
				throw new Execution_Exception( 'semantic_slot_heading_level_invalid', 'A semantic heading level must be between 1 and 6.' );
			}
			$attrs['level'] = $level;
			return $this->block_from_markup( $name, $attrs, '<h' . $level . ' class="wp-block-heading">' . $content . '</h' . $level . '>' );
		}
		if ( 'core/button' === $name ) {
			$url = isset( $attrs['url'] ) && is_string( $attrs['url'] ) ? esc_url( $attrs['url'] ) : '';
			$link = '<a class="wp-block-button__link wp-element-button"' . ( '' === $url ? '' : ' href="' . esc_attr( $url ) . '"' ) . '>' . $content . '</a>';
			return $this->block_from_markup( $name, $attrs, '<div class="wp-block-button">' . $link . '</div>' );
		}
		if ( 'core/separator' === $name ) {
			return $this->block_from_markup( $name, $attrs, '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );
		}
		if ( 'core/spacer' === $name ) {
			$height = isset( $attrs['height'] ) && is_string( $attrs['height'] ) && preg_match( '/^[0-9]+(?:px|rem|em|vh|vw|%)$/', $attrs['height'] ) ? $attrs['height'] : '100px';
			$attrs['height'] = $height;
			return $this->block_from_markup( $name, $attrs, '<div style="height:' . esc_attr( $height ) . '" aria-hidden="true" class="wp-block-spacer"></div>' );
		}
		if ( 'core/image' === $name ) {
			$attachment_id = absint( $spec['attachment_id'] ?? 0 );
			$size_slug     = sanitize_key( (string) ( $spec['size_slug'] ?? 'full' ) );
			$attachment    = get_post( $attachment_id );
			if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $attachment_id ) || ! current_user_can( 'read_post', $attachment_id ) ) {
				throw new Execution_Exception( 'image_attachment_not_found', 'The semantic slot image must reference a readable Media Library image.' );
			}
			$source = wp_get_attachment_image_src( $attachment_id, $size_slug );
			if ( ! is_array( $source ) || empty( $source[0] ) ) {
				throw new Execution_Exception( 'image_markup_unavailable', 'WordPress could not resolve the semantic slot image source.' );
			}
			$attrs['id']       = $attachment_id;
			$attrs['sizeSlug'] = $size_slug;
			$alt = wp_strip_all_tags( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
			$html = '<figure class="wp-block-image size-' . esc_attr( $size_slug ) . '"><img src="' . esc_url( (string) $source[0] ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $attachment_id . '"/></figure>';
			return $this->block_from_markup( $name, $attrs, $html );
		}
		throw new Execution_Exception( 'semantic_slot_block_unsupported', 'This core block does not have a semantic slot materializer. Use a supported core block or an approved provider component.' );
	}

	private function block_from_markup( string $name, array $attrs, string $html ): array {
		$comment = '<!-- wp:' . substr( $name, 5 );
		if ( ! empty( $attrs ) ) {
			$comment .= ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		$blocks = parse_blocks( $comment . ' -->' . $html . '<!-- /wp:' . substr( $name, 5 ) . ' -->' );
		if ( 1 !== count( $blocks ) || $name !== (string) ( $blocks[0]['blockName'] ?? '' ) ) {
			throw new Execution_Exception( 'semantic_slot_materialization_failed', 'The semantic core block could not be materialized canonically.' );
		}
		return $blocks[0];
	}

	private function mark_user_tree( array $block, array $contract, string $slot_id, ?string $preserve_id = null ): array {
		$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$metadata   = isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ? $attrs['metadata'] : array();
		$namespaced = isset( $metadata['wpsuiteAgentComposer'] ) && is_array( $metadata['wpsuiteAgentComposer'] ) ? $metadata['wpsuiteAgentComposer'] : array();
		$user_id    = null !== $preserve_id ? $preserve_id : $this->new_user_block_id();
		$namespaced['contractId']      = (string) ( $contract['id'] ?? '' );
		$namespaced['contractVersion'] = (int) ( $contract['version'] ?? 0 );
		$namespaced['ownership']       = 'USER';
		$namespaced['slotId']          = $slot_id;
		$namespaced['userBlockId']     = $user_id;
		$metadata['wpsuiteAgentComposer'] = $namespaced;
		$attrs['metadata'] = $metadata;
		unset( $attrs['lock'], $attrs['templateLock'] );
		$block['attrs'] = $attrs;
		$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		foreach ( $children as $index => $child ) {
			if ( is_array( $child ) && null !== ( $child['blockName'] ?? null ) ) {
				$children[ $index ] = $this->mark_user_tree( $child, $contract, $slot_id );
			}
		}
		$block['innerBlocks'] = $children;
		return $block;
	}

	private function new_user_block_id(): string {
		$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		return 'user-' . strtolower( $uuid );
	}

	private function valid_user_block_id( string $user_id ): string {
		$user_id = strtolower( trim( $user_id ) );
		if ( 1 !== preg_match( '/^user-[a-z0-9-]{8,58}$/', $user_id ) ) {
			throw new Execution_Exception( 'semantic_slot_block_id_invalid', 'A valid stable user_block_id is required.' );
		}
		return $user_id;
	}

	private function user_block_id( array $block ): string {
		return (string) ( $block['attrs']['metadata']['wpsuiteAgentComposer']['userBlockId'] ?? '' );
	}

	private function direct_user_index( array $blocks, string $user_id ): ?int {
		$found = null;
		foreach ( $blocks as $index => $block ) {
			if ( is_array( $block ) && hash_equals( $user_id, $this->user_block_id( $block ) ) ) {
				if ( null !== $found ) {
					throw new Execution_Exception( 'semantic_slot_block_ambiguous', 'The stable user_block_id occurs more than once.' );
				}
				$found = (int) $index;
			}
		}
		return $found;
	}

	private function slot_insertion_index( array $blocks, string $position, string $reference ): int {
		$position = sanitize_key( $position );
		if ( 'start' === $position ) {
			return 0;
		}
		if ( 'end' === $position || '' === $position ) {
			return count( $blocks );
		}
		if ( ! in_array( $position, array( 'before', 'after' ), true ) ) {
			throw new Execution_Exception( 'semantic_slot_position_invalid', 'Slot position must be start, end, before, or after.' );
		}
		$index = $this->direct_user_index( $blocks, $this->valid_user_block_id( $reference ) );
		if ( null === $index ) {
			throw new Execution_Exception( 'semantic_slot_reference_not_found', 'The reference user-owned block is not a direct child of the selected slot.' );
		}
		return 'after' === $position ? $index + 1 : $index;
	}

	private function find_user_occurrences( array $blocks, string $user_id, array $path ): array {
		$result   = array();
		$position = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			$block_path = array_merge( $path, array( $position ) );
			++$position;
			if ( hash_equals( $user_id, $this->user_block_id( $block ) ) ) {
				$result[] = array( 'path' => $block_path, 'block' => $block );
			}
			$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			$result = array_merge( $result, $this->find_user_occurrences( $children, $user_id, $block_path ) );
		}
		return $result;
	}

	/** Replace one user-owned node inside a slot while preserving canonical child placeholders. */
	private function replace_slot_child_at_path( array &$children, array $path, array $replacement ): void {
		$index = array_shift( $path );
		if ( ! is_int( $index ) || ! isset( $children[ $index ] ) ) {
			throw new Execution_Exception( 'semantic_slot_block_path_invalid', 'The selected user-owned block no longer exists in the extension slot.' );
		}
		if ( empty( $path ) ) {
			array_splice( $children, $index, 1, $replacement );
			return;
		}

		$parent = &$children[ $index ];
		$child_index = array_shift( $path );
		if ( ! is_int( $child_index ) || ! isset( $parent['innerBlocks'][ $child_index ] ) ) {
			throw new Execution_Exception( 'semantic_slot_block_path_invalid', 'The selected user-owned block no longer exists in the extension slot.' );
		}
		if ( empty( $path ) ) {
			$positions = array();
			foreach ( (array) ( $parent['innerContent'] ?? array() ) as $position => $part ) {
				if ( null === $part ) {
					$positions[] = $position;
				}
			}
			$content_position = $positions[ $child_index ] ?? null;
			if ( ! is_int( $content_position ) || count( $positions ) !== count( (array) $parent['innerBlocks'] ) ) {
				throw new Execution_Exception( 'semantic_slot_block_parent_invalid', 'The selected user-owned block does not have canonical parent markup.' );
			}
			array_splice( $parent['innerBlocks'], $child_index, 1, $replacement );
			array_splice( $parent['innerContent'], $content_position, 1, array_fill( 0, count( $replacement ), null ) );
			return;
		}

		$descendants = &$parent['innerBlocks'];
		$this->replace_slot_child_at_path( $descendants, array_merge( array( $child_index ), $path ), $replacement );
	}

	private function save_slot_children( array $input, array $context, array $children ): array {
		if ( 'synced' === (string) ( $context['storage'] ?? '' ) ) {
			$owner = $this->synced_patterns->with_slot_children( $context['block'], (string) $context['slot_id'], $children, (string) ( $context['pattern_instance_id'] ?? '' ) );
			return $this->drafts->insert_or_update_blocks(
				array(
					'post_id'               => $context['post_id'],
					'expected_modified_gmt' => (string) ( $input['expected_modified_gmt'] ?? '' ),
					'expected_revision'     => (string) ( $input['expected_revision'] ?? '' ),
					'mode'                  => 'replace',
					'path'                  => $context['path'],
					'blocks'                => array( $owner ),
				)
			);
		}
		$slot = $context['block'];
		$slot['innerBlocks']  = array_values( $children );
		$slot['innerContent'] = $this->slot_inner_content( (array) ( $slot['innerContent'] ?? array() ), (string) ( $slot['innerHTML'] ?? '' ), count( $children ) );
		return $this->drafts->insert_or_update_blocks(
			array(
				'post_id'               => $context['post_id'],
				'expected_modified_gmt' => (string) ( $input['expected_modified_gmt'] ?? '' ),
				'expected_revision'     => (string) ( $input['expected_revision'] ?? '' ),
				'mode'                  => 'replace',
				'path'                  => $context['path'],
				'blocks'                => array( $slot ),
			)
		);
	}

	private function slot_inner_content( array $inner_content, string $inner_html, int $children ): array {
		$first = array_search( null, $inner_content, true );
		$last  = null;
		foreach ( $inner_content as $index => $part ) {
			if ( null === $part ) {
				$last = $index;
			}
		}
		if ( false !== $first && null !== $last ) {
			$prefix = implode( '', array_filter( array_slice( $inner_content, 0, (int) $first ), 'is_string' ) );
			$suffix = implode( '', array_filter( array_slice( $inner_content, $last + 1 ), 'is_string' ) );
		} elseif ( 1 === preg_match( '#^(.*?>)(.*)(</[a-z0-9:-]+>\s*)$#is', $inner_html, $match ) ) {
			$prefix = (string) $match[1] . (string) $match[2];
			$suffix = (string) $match[3];
		} else {
			throw new Execution_Exception( 'semantic_slot_wrapper_invalid', 'The extension slot wrapper cannot safely accept child blocks.' );
		}
		return array_merge( array( $prefix ), array_fill( 0, $children, null ), array( $suffix ) );
	}

	/**
	 * Build a transport-safe semantic projection without exposing Gutenberg paths
	 * or serialized block comments. Public for deterministic contract testing.
	 */
	public function project_document( array $contract, array $blocks ): array {
		$nodes = array();
		foreach ( (array) ( $contract['nodes'] ?? array() ) as $node ) {
			if ( is_array( $node ) && isset( $node['id'] ) ) {
				$nodes[ (string) $node['id'] ] = $node;
			}
		}

		$matches = array();
		$slots   = array();
		$this->inspect_blocks( $blocks, $nodes, null, $matches, $slots );

		$projected_nodes = array();
		foreach ( $nodes as $id => $definition ) {
			$occurrences = (array) ( $matches[ $id ] ?? array() );
			$item = array(
				'id'                  => $id,
				'block'               => (string) ( $definition['block'] ?? '' ),
				'ownership'           => (string) ( $definition['ownership'] ?? '' ),
				'mode'                => (string) ( $definition['mode'] ?? '' ),
				'parent'              => $definition['parent'] ?? null,
				'present'             => 1 === count( $occurrences ),
				'occurrences'         => count( $occurrences ),
				'editable_attributes' => array_values( (array) ( $definition['editable_attributes'] ?? array() ) ),
				'editable_content'    => true === ( $definition['editable_content'] ?? false ),
			);
			if ( 1 === count( $occurrences ) ) {
				$block  = $occurrences[0];
				$attrs  = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$values = array();
				foreach ( $item['editable_attributes'] as $attribute ) {
					if ( array_key_exists( $attribute, $attrs ) && ! $this->reserved_attribute( $attribute ) ) {
						$values[ $attribute ] = $attrs[ $attribute ];
					}
				}
				$item['values'] = $values;
				if ( 'core/image' === (string) $block['blockName'] ) {
					$item['media'] = $this->image_projection( $block );
				} elseif ( $item['editable_content'] ) {
					$item['content']        = $this->semantic_content( (string) $block['blockName'], (string) ( $block['innerHTML'] ?? '' ) );
					$item['content_format'] = 'richtext';
				}
			}
			if ( 'slot' === $item['mode'] ) {
				$item['allowed_blocks'] = array_values( (array) ( $definition['allowed_blocks'] ?? array() ) );
				$item['allowed_patterns'] = array_values( (array) ( $definition['allowed_patterns'] ?? array() ) );
				$item['pattern_occurrences'] = (array) ( $definition['pattern_occurrences'] ?? array() );
				$item['min_blocks']     = (int) ( $definition['min_blocks'] ?? 0 );
				$item['max_blocks']     = $definition['max_blocks'] ?? null;
				$item['blocks']         = array_values( (array) ( $slots[ $id ] ?? array() ) );
			}
			$projected_nodes[] = $item;
		}

		return array(
			'nodes' => $projected_nodes,
			'field_ids' => array_values(
				array_map(
					static fn( array $node ): string => (string) $node['id'],
					array_filter( $projected_nodes, static fn( array $node ): bool => 'INSTANCE_CONTENT' === $node['ownership'] )
				)
			),
			'media_field_ids' => array_values(
				array_map(
					static fn( array $node ): string => (string) $node['id'],
					array_filter( $projected_nodes, static fn( array $node ): bool => 'INSTANCE_CONTENT' === $node['ownership'] && 'core/image' === $node['block'] )
				)
			),
			'slot_ids' => array_values(
				array_map(
					static fn( array $node ): string => (string) $node['id'],
					array_filter( $projected_nodes, static fn( array $node ): bool => 'slot' === $node['mode'] )
				)
			),
		);
	}

	private function inspect_blocks( array $blocks, array $nodes, ?string $slot_id, array &$matches, array &$slots ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			$node_id = $this->semantic_identity( $block, $nodes );
			if ( null !== $node_id ) {
				$matches[ $node_id ][] = $block;
			}
			$current_slot = null !== $node_id && 'slot' === (string) ( $nodes[ $node_id ]['mode'] ?? '' ) ? $node_id : $slot_id;
			$children     = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			if ( null !== $current_slot && $current_slot !== $slot_id ) {
				$slots[ $current_slot ] = $this->user_blocks( $children, $current_slot );
			}
			$this->inspect_blocks( $children, $nodes, $current_slot, $matches, $slots );
		}
	}

	private function user_blocks( array $blocks, string $slot_id ): array {
		$result = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$metadata   = isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ? $attrs['metadata'] : array();
			$composer   = isset( $metadata['wpsuiteAgentComposer'] ) && is_array( $metadata['wpsuiteAgentComposer'] ) ? $metadata['wpsuiteAgentComposer'] : array();
			$children   = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			unset( $metadata['wpsuiteAgentComposer'], $attrs['lock'], $attrs['templateLock'] );
			if ( empty( $metadata ) ) {
				unset( $attrs['metadata'] );
			} else {
				$attrs['metadata'] = $metadata;
			}
			$result[] = array(
				'user_block_id' => is_string( $composer['userBlockId'] ?? null ) ? trim( $composer['userBlockId'] ) : '',
				'slot_id'       => $slot_id,
				'block'         => (string) $block['blockName'],
				'attributes'    => $attrs,
				'content'       => $this->semantic_content( (string) $block['blockName'], (string) ( $block['innerHTML'] ?? '' ) ),
				'content_format' => 'richtext',
				'inner_blocks'  => $this->user_blocks( $children, $slot_id ),
			);
		}
		return $result;
	}

	private function semantic_identity( array $block, array $nodes ): ?string {
		$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$metadata   = isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ? $attrs['metadata'] : array();
		$composer   = isset( $attrs['composer'] ) && is_array( $attrs['composer'] ) ? $attrs['composer'] : array();
		$namespaced = isset( $metadata['wpsuiteAgentComposer'] ) && is_array( $metadata['wpsuiteAgentComposer'] ) ? $metadata['wpsuiteAgentComposer'] : array();
		$candidates = array_values(
			array_unique(
				array_filter(
					array(
						is_string( $composer['nodeId'] ?? null ) ? trim( $composer['nodeId'] ) : '',
						is_string( $namespaced['nodeId'] ?? null ) ? trim( $namespaced['nodeId'] ) : '',
						is_string( $metadata['name'] ?? null ) ? trim( $metadata['name'] ) : '',
					),
					static fn( string $candidate ): bool => isset( $nodes[ $candidate ] )
				)
			)
		);
		return 1 === count( $candidates ) ? $candidates[0] : null;
	}

	private function semantic_content( string $block_name, string $html ): string {
		$html = trim( $html );
		if ( 'core/button' === $block_name && 1 === preg_match( '#<a\b[^>]*>(.*)</a>#is', $html, $matches ) ) {
			return (string) $matches[1];
		}
		if ( 1 === preg_match( '#^<([a-z][a-z0-9]*)\b[^>]*>(.*)</\1>$#is', $html, $matches ) ) {
			return (string) $matches[2];
		}
		return $html;
	}

	private function image_projection( array $block ): array {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$html  = (string) ( $block['innerHTML'] ?? '' );
		preg_match( '/<img\b[^>]*>/i', $html, $image_match );
		$image = (string) ( $image_match[0] ?? '' );
		$caption = '';
		if ( 1 === preg_match( '/<figcaption\b[^>]*>(.*?)<\/figcaption>/is', $html, $caption_match ) ) {
			$caption = (string) $caption_match[1];
		}
		return array(
			'attachment_id' => absint( $attrs['id'] ?? 0 ),
			'size_slug'     => sanitize_key( (string) ( $attrs['sizeSlug'] ?? '' ) ),
			'link_destination' => sanitize_key( (string) ( $attrs['linkDestination'] ?? 'none' ) ),
			'url'           => (string) ( $this->html_attribute( $image, 'src' ) ?? '' ),
			'alt'           => (string) ( $this->html_attribute( $image, 'alt' ) ?? '' ),
			'title'         => (string) ( $this->html_attribute( $image, 'title' ) ?? '' ),
			'caption'       => $caption,
			'caption_format' => 'richtext',
		);
	}

	private function replace_block_content( array $block, string $value ): array {
		if ( strlen( $value ) > 250000 ) {
			throw new Execution_Exception( 'semantic_field_value_too_large', 'Semantic rich-text content cannot exceed 250,000 bytes.' );
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			throw new Execution_Exception( 'semantic_field_content_nested', 'A semantic rich-text field cannot replace a block that contains nested blocks.' );
		}
		$value      = wp_kses_post( $value );
		$name       = (string) ( $block['blockName'] ?? '' );
		$inner_html = (string) ( $block['innerHTML'] ?? '' );
		$pattern    = 'core/button' === $name
			? '#^(.*<a\b[^>]*>)(.*)(</a>.*)$#is'
			: '#^(\s*<([a-z][a-z0-9]*)\b[^>]*>)(.*)(</\2>\s*)$#is';
		if ( 1 !== preg_match( $pattern, $inner_html, $matches ) ) {
			throw new Execution_Exception( 'semantic_field_content_shell_unsupported', 'The semantic field block does not have a supported rich-text content shell.' );
		}
		if ( 'core/button' === $name ) {
			$inner_html = (string) $matches[1] . $value . (string) $matches[3];
		} else {
			$inner_html = (string) $matches[1] . $value . (string) $matches[4];
		}
		$block['innerHTML']   = $inner_html;
		$block['innerContent'] = array( $inner_html );
		return $block;
	}

	private function replace_image_block(
		array $block,
		int $attachment_id,
		string $size_slug,
		string $image_url,
		string $media_url,
		string $alt,
		string $title
	): array {
		$attrs            = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$link_destination = sanitize_key( (string) ( $attrs['linkDestination'] ?? 'none' ) );
		if ( ! in_array( $link_destination, array( 'none', 'media', 'attachment' ), true ) ) {
			throw new Execution_Exception( 'semantic_media_link_unsupported', 'Semantic media replacement supports only none, media, or attachment link destinations.' );
		}
		$inner_html = (string) ( $block['innerHTML'] ?? '' );
		if ( 1 !== preg_match_all( '/<img\b[^>]*>/i', $inner_html ) ) {
			throw new Execution_Exception( 'semantic_media_markup_invalid', 'The semantic media field must contain exactly one Image element.' );
		}

		preg_match( '/<img\b[^>]*>/i', $inner_html, $image_match );
		$image_html   = (string) ( $image_match[0] ?? '' );
		$image_classes = preg_split( '/\s+/', trim( (string) $this->html_attribute( $image_html, 'class' ) ) ) ?: array();
		$image_classes = array_values( array_filter( $image_classes, static fn( string $class ): bool => 1 !== preg_match( '/^wp-image-[0-9]+$/', $class ) ) );
		$image_classes[] = 'wp-image-' . $attachment_id;
		$image_html = $this->set_html_attribute( $image_html, 'src', esc_url( $image_url ) );
		$image_html = $this->set_html_attribute( $image_html, 'alt', esc_attr( $alt ) );
		$image_html = $this->set_html_attribute( $image_html, 'class', esc_attr( implode( ' ', array_unique( $image_classes ) ) ) );
		$image_html = '' === $title
			? $this->remove_html_attribute( $image_html, 'title' )
			: $this->set_html_attribute( $image_html, 'title', esc_attr( $title ) );
		$image_html = $this->remove_html_attribute( $this->remove_html_attribute( $image_html, 'srcset' ), 'sizes' );
		$inner_html = (string) preg_replace( '/<img\b[^>]*>/i', $image_html, $inner_html, 1 );
		preg_match( '/^<figure\b[^>]*>/i', trim( $inner_html ), $figure_match );
		$figure_tag = (string) ( $figure_match[0] ?? '' );
		if ( '' === $figure_tag ) {
			throw new Execution_Exception( 'semantic_media_markup_invalid', 'The semantic media field must retain its Figure wrapper.' );
		}
		$figure_classes = preg_split( '/\s+/', trim( (string) $this->html_attribute( $figure_tag, 'class' ) ) ) ?: array();
		$figure_classes = array_values( array_filter( $figure_classes, static fn( string $class ): bool => ! str_starts_with( $class, 'size-' ) ) );
		$figure_classes[] = 'size-' . $size_slug;
		$updated_figure = $this->set_html_attribute( $figure_tag, 'class', esc_attr( implode( ' ', array_unique( $figure_classes ) ) ) );
		$inner_html = preg_replace( '/^\s*<figure\b[^>]*>/i', $updated_figure, $inner_html, 1 ) ?? $inner_html;

		if ( 'media' === $link_destination ) {
			$link_url = $media_url;
		} elseif ( 'attachment' === $link_destination ) {
			$link_url = get_attachment_link( $attachment_id );
			if ( ! is_string( $link_url ) || '' === $link_url ) {
				throw new Execution_Exception( 'image_link_unavailable', 'The image attachment URL is unavailable.' );
			}
		} else {
			$link_url = '';
		}
		if ( '' !== $link_url ) {
			$count      = 0;
			$inner_html = (string) preg_replace_callback(
				'/(<a\b[^>]*\bhref\s*=\s*)(["\'])(.*?)(\2)/is',
				static fn( array $match ): string => (string) $match[1] . (string) $match[2] . esc_url( $link_url ) . (string) $match[4],
				$inner_html,
				1,
				$count
			);
			if ( 1 !== $count ) {
				throw new Execution_Exception( 'semantic_media_link_invalid', 'The linked semantic media field does not contain one replaceable link destination.' );
			}
		}

		$attrs['id'] = $attachment_id;
		if ( array_key_exists( 'sizeSlug', $attrs ) ) {
			$attrs['sizeSlug'] = $size_slug;
		}
		$block['attrs'] = $attrs;
		$block['innerHTML'] = $inner_html;
		$block['innerContent'] = array( $inner_html );
		return $block;
	}

	private function image_size_slug( array $block ): string {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$slug  = sanitize_key( (string) ( $attrs['sizeSlug'] ?? '' ) );
		if ( '' !== $slug ) {
			return $slug;
		}
		if ( 1 === preg_match( '/<figure\b[^>]*\bclass\s*=\s*(["\'])(.*?)\1/is', (string) ( $block['innerHTML'] ?? '' ), $match ) ) {
			foreach ( preg_split( '/\s+/', trim( (string) $match[2] ) ) ?: array() as $class ) {
				if ( str_starts_with( $class, 'size-' ) ) {
					$slug = sanitize_key( substr( $class, 5 ) );
					if ( '' !== $slug ) {
						return $slug;
					}
				}
			}
		}
		return 'full';
	}

	private function html_attribute( string $tag, string $attribute ): ?string {
		$attribute = preg_quote( $attribute, '/' );
		if ( 1 !== preg_match( '/\s' . $attribute . '\s*=\s*(["\'])(.*?)\1/is', $tag, $match ) ) {
			return null;
		}
		return html_entity_decode( (string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private function set_html_attribute( string $tag, string $attribute, string $value ): string {
		$quoted = preg_quote( $attribute, '/' );
		if ( 1 === preg_match( '/\s' . $quoted . '\s*=\s*(["\']).*?\1/is', $tag ) ) {
			return (string) preg_replace( '/\s' . $quoted . '\s*=\s*(["\']).*?\1/is', ' ' . $attribute . '="' . $value . '"', $tag, 1 );
		}
		$trimmed = rtrim( $tag );
		$suffix  = str_ends_with( $trimmed, '/>' ) ? '/>' : '>';
		return substr( $trimmed, 0, -strlen( $suffix ) ) . ' ' . $attribute . '="' . $value . '"' . $suffix;
	}

	private function remove_html_attribute( string $tag, string $attribute ): string {
		$attribute = preg_quote( $attribute, '/' );
		return (string) preg_replace( '/\s' . $attribute . '\s*=\s*(["\']).*?\1/is', '', $tag, 1 );
	}

	private function find_node_occurrences( array $blocks, array $nodes, string $field_id, array $path = array(), string $pattern_instance_id = '' ): array {
		$result   = array();
		$position = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			$block_path = array_merge( $path, array( $position ) );
			++$position;
			$block_pattern_instance_id = strtolower( trim( (string) ( $block['attrs']['metadata']['wpsuiteAgentComposer']['patternInstanceId'] ?? '' ) ) );
			if (
				$field_id === $this->semantic_identity( $block, $nodes )
				&& ( '' === $pattern_instance_id || hash_equals( $pattern_instance_id, $block_pattern_instance_id ) )
			) {
				$result[] = array( 'path' => $block_path, 'block' => $block );
			}
			$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			$result   = array_merge( $result, $this->find_node_occurrences( $children, $nodes, $field_id, $block_path, $pattern_instance_id ) );
		}
		return $result;
	}

	private function field_id_from_input( array $input ): string {
		$field_id = trim( (string) ( $input['field_id'] ?? '' ) );
		$legacy   = trim( (string) ( $input['field'] ?? '' ) );
		if ( '' !== $field_id && '' !== $legacy && ! hash_equals( $field_id, $legacy ) ) {
			throw new Execution_Exception( 'semantic_field_identity_conflict', 'field_id and the legacy field alias must identify the same semantic field.' );
		}
		$field_id = '' !== $field_id ? $field_id : $legacy;
		if ( '' === $field_id || strlen( $field_id ) > 128 || 1 !== preg_match( '/^[a-z][a-z0-9._-]{0,127}$/', $field_id ) ) {
			throw new Execution_Exception( 'semantic_field_id_invalid', 'A valid semantic field_id is required.' );
		}
		return $field_id;
	}

	private function pattern_instance_id_from_input( array $input ): string {
		$value = strtolower( trim( (string) ( $input['pattern_instance_id'] ?? '' ) ) );
		if ( '' !== $value && 1 !== preg_match( '/^pattern-[a-z0-9-]{8,58}$/', $value ) ) {
			throw new Execution_Exception( 'synced_pattern_instance_id_invalid', 'pattern_instance_id must be a stable Composer pattern instance identifier.' );
		}
		return $value;
	}

	private function assert_attribute_value( string $block_name, string $attribute, mixed $value ): void {
		if ( strlen( serialize( $value ) ) > 250000 ) {
			throw new Execution_Exception( 'semantic_field_value_too_large', 'A semantic attribute value cannot exceed 250,000 serialized bytes.' );
		}
		if ( ! class_exists( '\\WP_Block_Type_Registry' ) ) {
			throw new Execution_Exception( 'block_registry_unavailable', 'The WordPress block registry is unavailable.' );
		}
		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
		$schema     = is_object( $block_type ) && is_array( $block_type->attributes ?? null ) ? ( $block_type->attributes[ $attribute ] ?? null ) : null;
		if ( ! is_array( $schema ) ) {
			throw new Execution_Exception( 'semantic_field_attribute_unregistered', 'The semantic field attribute is not registered by its Gutenberg block type.' );
		}
		$type  = (string) ( $schema['type'] ?? '' );
		$valid = match ( $type ) {
			'string'  => is_string( $value ),
			'boolean' => is_bool( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'array'   => is_array( $value ) && array_is_list( $value ),
			'object'  => is_array( $value ) && ( empty( $value ) || ! array_is_list( $value ) ),
			default   => false,
		};
		if ( ! $valid || ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) ) {
			throw new Execution_Exception( 'semantic_field_value_invalid', 'The semantic field value does not satisfy the registered Gutenberg attribute schema.' );
		}
	}

	private function reserved_attribute( string $attribute ): bool {
		return in_array( $attribute, array( 'metadata', 'composer', 'lock', 'templateLock', 'slotId', 'allowedBlocks', 'allowedPatterns', 'patternOccurrences', 'minBlocks', 'maxBlocks' ), true );
	}

	private function enforced_contract( array $blueprint ): array {
		if ( 'enforced' !== (string) ( $blueprint['structure_contract_mode'] ?? '' ) || ! is_array( $blueprint['resolved_structure_contract'] ?? null ) ) {
			throw new Execution_Exception( 'semantic_contract_unavailable', 'The selected Blueprint does not have an enforced Structure Contract.' );
		}
		return $blueprint['resolved_structure_contract'];
	}
}
