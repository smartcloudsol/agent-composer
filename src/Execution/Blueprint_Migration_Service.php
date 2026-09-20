<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;
use SmartCloud\AgentComposer\Domain\Structure\StructureDocumentValidator;
use SmartCloud\AgentComposer\Infrastructure\WordPress\Activation;

/** Preview one exact versioned Blueprint migration and create its review proposal. */
final class Blueprint_Migration_Service {
	public function __construct(
		private readonly Config_Repository $config,
		private readonly Page_Validator $validator,
		private readonly Managed_Document_State $document_state,
		private readonly Synced_Structural_Pattern_Service $synced_patterns,
		private readonly Structure_Editor_Projector $editor,
		private readonly Content_Proposal_Service $proposals,
		private readonly StructureDocumentValidator $structure
	) {}

	/** Calculate a zero-write migration preview without returning serialized post_content. */
	public function preview( array $input ): array {
		$plan = $this->plan( $input );
		unset( $plan['_target_content'], $plan['_target_metadata'] );
		return $plan;
	}

	/** Recalculate an exact preview and create one inactive published-content proposal. */
	public function create_proposal( array $input ): array {
		if ( true !== ( $input['confirm_proposal'] ?? false ) ) {
			throw new Execution_Exception( 'migration_proposal_confirmation_required', 'Creating a migration proposal requires confirm_proposal=true.' );
		}
		$plan     = $this->plan( $input );
		$expected = strtolower( trim( (string) ( $input['expected_migration_plan_hash'] ?? '' ) ) );
		if ( '' === $expected || ! hash_equals( strtolower( (string) $plan['plan_hash'] ), $expected ) ) {
			throw new Execution_Exception( 'migration_plan_conflict', 'The migration preview changed. Review the fresh plan before creating a proposal.' );
		}
		if ( ! $plan['proposal_eligible'] ) {
			throw new Execution_Exception( 'migration_requires_review', 'This migration contains unresolved override conflicts or review-required state and cannot generate an automatic proposal.', 0, array( 'override_rebase' => $plan['override_rebase'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Typed conflict data is returned through the API, not rendered.
		}
		$result = $this->proposals->create_migration(
			array(
				'post_id'               => (int) $plan['post_id'],
				'page_type'             => (string) $plan['page_type'],
				'content_language'      => (string) ( $input['content_language'] ?? '' ),
				'expected_modified_gmt' => (string) ( $input['expected_modified_gmt'] ?? '' ),
				'expected_content_hash' => (string) ( $input['expected_content_hash'] ?? '' ),
				'idempotency_key'       => (string) ( $input['idempotency_key'] ?? '' ),
				'confirm_proposal'      => true,
			),
			array(
				'content'        => $plan['_target_content'],
				'managed_meta'   => $plan['_target_metadata'],
				'migration'      => array(
					'schema_version'        => 1,
					'id'                    => $plan['migration_id'],
					'from_baseline'         => $plan['from_baseline'],
					'to_baseline'           => $plan['to_baseline'],
					'plan_hash'             => $plan['plan_hash'],
					'target_content_hash'   => $plan['target_content_hash'],
					'structural_fingerprint' => $plan['validation']['structure_contract']['structural_fingerprint'] ?? '',
					'structural_change'     => true,
					'override_rebase'       => $plan['override_rebase'],
				),
			)
		);
		$result['migration_preview'] = $this->public_plan( $plan );
		return $result;
	}

	private function plan( array $input ): array {
		if ( ! current_user_can( Activation::CAP_RUN_MIGRATIONS ) ) {
			throw new Execution_Exception( 'migration_permission_denied', 'The current user cannot run governed Blueprint migrations.' );
		}
		$post_id      = absint( $input['post_id'] ?? 0 );
		$page_type    = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$migration_id = sanitize_key( (string) ( $input['migration_id'] ?? '' ) );
		$post          = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! current_user_can( 'read_post', $post_id ) ) {
			throw new Execution_Exception( 'migration_source_not_found', 'A migration source must be a readable published content item.' );
		}
		$blueprint = $this->config->get_blueprint( $page_type );
		if ( $post->post_type !== (string) $blueprint['target_post_type'] ) {
			throw new Execution_Exception( 'migration_source_type_mismatch', 'The source post type does not match the migration Blueprint.' );
		}
		$migration = $this->config->get_structure_migrations()[ $migration_id ] ?? null;
		if ( ! is_array( $migration ) || $page_type !== (string) ( $migration['blueprint'] ?? '' ) ) {
			throw new Execution_Exception( 'migration_not_found', 'No exact registered Structure migration exists for this Blueprint.' );
		}
		$this->assert_target_baseline( $blueprint, $migration );

		$source_state = $this->document_state->migration_source_state( $post_id );
		$from_baseline = $this->baseline_for_response( $page_type, (array) $migration['from'] );
		$to_baseline   = $this->baseline_for_response( $page_type, (array) $migration['to'] );
		if ( $source_state['baseline']['blueprint'] !== $from_baseline['blueprint'] || $source_state['baseline']['structure_contract'] !== $from_baseline['structure_contract'] ) {
			throw new Execution_Exception( 'migration_source_version_mismatch', 'The managed source baseline does not match this migration route.' );
		}

		$raw_blocks = parse_blocks( (string) $post->post_content );
		try {
			$expanded_source = $this->synced_patterns->expand_blocks( $raw_blocks, $blueprint );
		} catch ( Execution_Exception $error ) {
			throw new Execution_Exception( 'migration_source_pattern_invalid', 'The migration source contains an invalid synced structural pattern.' );
		}
		$source_validation = $this->structure->validate( (array) $migration['from_contract'], $expanded_source );
		if ( ! $source_validation['valid'] ) {
			throw new Execution_Exception( 'migration_source_drift', 'The source no longer matches its registered historical Structure Contract.', 0, array( 'violations' => $source_validation['errors'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Typed violations are returned through the API, not rendered.
		}
		$stored_hash = (string) ( $source_state['baseline']['contract_hash'] ?? $source_state['contract_hash'] ?? '' );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, (string) $source_validation['contract_hash'] ) ) {
			throw new Execution_Exception( 'migration_source_contract_hash_mismatch', 'The source baseline hash does not match the registered historical Structure Contract.' );
		}

		$rebase = $this->rebase_manifest( (array) $source_state['manifest'], $migration, (array) $blueprint['resolved_structure_contract'] );
		$target_blocks = $this->apply_operations( $raw_blocks, $migration, $blueprint, $rebase['preserve_current_order'] );
		$target_content = $this->editor->project_content( (array) $blueprint['resolved_structure_contract'], serialize_blocks( $target_blocks ) );
		$target_validation = $this->validator->validate( $page_type, $target_content, (string) $post->post_content, array( 'allow_structure_change' => true ) );
		$target_metadata   = array();
		if ( true === ( $target_validation['valid'] ?? false ) ) {
			$target_metadata = $this->document_state->metadata_for_migration( $page_type, $target_validation, $rebase['manifest'] );
		}

		$source_content_hash = hash( 'sha256', (string) $post->post_content );
		$target_content_hash = hash( 'sha256', $target_content );
		$public = array(
			'post_id'             => $post_id,
			'page_type'           => $page_type,
			'migration_id'        => $migration_id,
			'from_baseline'       => $from_baseline,
			'to_baseline'         => $to_baseline,
			'structural_change'   => true,
			'operations'          => $this->operation_report( (array) $migration['operations'], $rebase['preserve_current_order'] ),
			'override_rebase'     => $rebase['report'],
			'validation'          => $target_validation,
			'proposal_eligible'   => true === ( $target_validation['valid'] ?? false ) && $rebase['automatic'],
			'source_modified_gmt' => $this->time_token( (string) $post->post_modified_gmt ),
			'source_content_hash' => $source_content_hash,
			'target_content_hash' => $target_content_hash,
		);
		$public['plan_hash'] = CanonicalJson::checksum( $public );
		$public['_target_content']  = $target_content;
		$public['_target_metadata'] = $target_metadata;
		return $public;
	}

	private function assert_target_baseline( array $blueprint, array $migration ): void {
		$target = (array) ( $migration['to'] ?? array() );
		if (
			(int) ( $blueprint['schema_version'] ?? 0 ) !== (int) ( $target['blueprint_version'] ?? 0 )
			|| ( $blueprint['structure_contract'] ?? null ) !== ( $target['structure_contract'] ?? null )
		) {
			throw new Execution_Exception( 'migration_target_version_mismatch', 'The active Blueprint and Structure Contract do not match the migration target.' );
		}
	}

	private function rebase_manifest( array $manifest, array $migration, array $target_contract ): array {
		$target_paths = array_fill_keys( array_map( 'strval', array_column( (array) ( $target_contract['nodes'] ?? array() ), 'id' ) ), true );
		$rules = array();
		foreach ( (array) ( $migration['override_rules'] ?? array() ) as $rule ) {
			$rules[ (string) $rule['type'] . ':' . (string) $rule['path'] ] = $rule;
		}
		$field_maps = array();
		foreach ( (array) ( $migration['operations'] ?? array() ) as $operation ) {
			if ( 'map_field' === (string) ( $operation['type'] ?? '' ) ) {
				$field_maps[ (string) $operation['from'] ] = (string) $operation['to'];
			}
		}
		$report = array( 'preserved' => array(), 'rebased' => array(), 'rejected' => array(), 'review_required' => array(), 'conflicting' => array(), 'detached' => array() );
		$result = array();
		$preserve_current_order = array();
		foreach ( (array) ( $manifest['overrides'] ?? array() ) as $override ) {
			$type = strtoupper( (string) ( $override['type'] ?? '' ) );
			$path = (string) ( $override['path'] ?? '' );
			$item = array( 'type' => $type, 'path' => $path );
			$rule = $rules[ $type . ':' . $path ] ?? null;
			if ( 'DETACHED' === $type ) {
				$report['detached'][] = $item;
				continue;
			}
			if ( is_array( $rule ) ) {
				$strategy = (string) $rule['strategy'];
				if ( 'map' === $strategy ) {
					$target = (string) $rule['target_path'];
					if ( ! isset( $target_paths[ $target ] ) ) {
						$report['conflicting'][] = $item + array( 'reason' => 'target-path-missing', 'target_path' => $target );
						continue;
					}
					$result[] = array( 'type' => $type, 'path' => $target );
					$report['rebased'][] = $item + array( 'strategy' => $strategy, 'target_path' => $target );
					continue;
				}
				if ( 'accept_target' === $strategy ) {
					$report['rebased'][] = $item + array( 'strategy' => $strategy );
					continue;
				}
				if ( 'preserve_current' === $strategy ) {
					if ( ! isset( $target_paths[ $path ] ) ) {
						$report['conflicting'][] = $item + array( 'reason' => 'target-path-missing' );
						continue;
					}
					$preserve_current_order[ $path ] = true;
					$result[] = $item;
					$report['rebased'][] = $item + array( 'strategy' => $strategy );
					continue;
				}
			}
			if ( isset( $field_maps[ $path ] ) && in_array( $type, array( 'CONTENT', 'VISIBILITY' ), true ) ) {
				$target = $field_maps[ $path ];
				if ( isset( $target_paths[ $target ] ) ) {
					$result[] = array( 'type' => $type, 'path' => $target );
					$report['rebased'][] = $item + array( 'strategy' => 'map_field', 'target_path' => $target );
					continue;
				}
			}
			if ( in_array( $type, array( 'CONTENT', 'VISIBILITY' ), true ) && isset( $target_paths[ $path ] ) ) {
				$result[] = $item;
				$report['preserved'][] = $item;
			} elseif ( in_array( $type, array( 'ORDER', 'STRUCTURE' ), true ) ) {
				$report['review_required'][] = $item + array( 'reason' => 'explicit-rebase-rule-required' );
			} else {
				$report['conflicting'][] = $item + array( 'reason' => 'target-path-missing' );
			}
		}
		foreach ( $report as &$items ) {
			usort( $items, static fn( array $left, array $right ): int => ( $left['type'] . ':' . $left['path'] ) <=> ( $right['type'] . ':' . $right['path'] ) );
		}
		unset( $items );
		$automatic = empty( $report['rejected'] ) && empty( $report['review_required'] ) && empty( $report['conflicting'] ) && empty( $report['detached'] );
		return array(
			'manifest' => array( 'schema_version' => 1, 'overrides' => $result ),
			'report' => $report,
			'automatic' => $automatic,
			'preserve_current_order' => $preserve_current_order,
		);
	}

	private function apply_operations( array $blocks, array $migration, array $blueprint, array $preserve_current_order ): array {
		$nodes = array_fill_keys( array_map( 'strval', array_column( (array) ( $blueprint['resolved_structure_contract']['nodes'] ?? array() ), 'id' ) ), true );
		foreach ( (array) $migration['operations'] as $operation ) {
			$type = (string) $operation['type'];
			if ( 'move_section' === $type ) {
				if ( isset( $preserve_current_order[ (string) $operation['id'] ] ) ) {
					continue;
				}
				$blocks = $this->move_top_level_section( $blocks, $operation, $blueprint, $nodes );
			} elseif ( 'add_section' === $type ) {
				$instance = $this->synced_patterns->materialize_instance( (string) $operation['pattern'], (array) $operation['fields'], $blueprint );
				$blocks   = $this->insert_top_level_section( $blocks, $instance['block'], $operation, $blueprint, $nodes );
			} elseif ( 'add_field' === $type && null !== $operation['default'] ) {
				$blocks = $this->set_pattern_override( $blocks, (string) $operation['id'], $operation['default'], false );
			} elseif ( 'map_field' === $type ) {
				$blocks = $this->map_pattern_override( $blocks, (string) $operation['from'], (string) $operation['to'] );
			}
		}
		return $blocks;
	}

	private function move_top_level_section( array $blocks, array $operation, array $blueprint, array $nodes ): array {
		$source = $this->top_level_index( $blocks, (string) $operation['id'], $blueprint, $nodes );
		$reference_id = (string) ( $operation['before'] ?? $operation['after'] ?? '' );
		$reference = $this->top_level_index( $blocks, $reference_id, $blueprint, $nodes );
		if ( null === $source || null === $reference ) {
			throw new Execution_Exception( 'migration_section_not_found', 'A move_section operation could not resolve both exact top-level semantic sections.' );
		}
		$block = $blocks[ $source ];
		array_splice( $blocks, $source, 1 );
		$reference = $this->top_level_index( $blocks, $reference_id, $blueprint, $nodes );
		$index = isset( $operation['before'] ) ? $reference : $reference + 1;
		array_splice( $blocks, $index, 0, array( $block ) );
		return $blocks;
	}

	private function insert_top_level_section( array $blocks, array $block, array $operation, array $blueprint, array $nodes ): array {
		if ( null !== $this->top_level_index( $blocks, (string) $operation['id'], $blueprint, $nodes ) ) {
			throw new Execution_Exception( 'migration_section_already_exists', 'An add_section operation would duplicate a semantic section.' );
		}
		$reference_id = (string) ( $operation['before'] ?? $operation['after'] ?? '' );
		$reference = $this->top_level_index( $blocks, $reference_id, $blueprint, $nodes );
		if ( null === $reference ) {
			throw new Execution_Exception( 'migration_section_reference_not_found', 'An add_section operation could not resolve its top-level reference section.' );
		}
		$index = isset( $operation['before'] ) ? $reference : $reference + 1;
		array_splice( $blocks, $index, 0, array( $block ) );
		return $blocks;
	}

	private function top_level_index( array $blocks, string $id, array $blueprint, array $nodes ): ?int {
		$matches = array();
		foreach ( $blocks as $index => $block ) {
			if ( is_array( $block ) && $id === $this->top_level_semantic_id( $block, $blueprint, $nodes ) ) {
				$matches[] = (int) $index;
			}
		}
		if ( count( $matches ) > 1 ) {
			throw new Execution_Exception( 'migration_section_ambiguous', 'A semantic section resolves more than once at the document root.' );
		}
		return 1 === count( $matches ) ? $matches[0] : null;
	}

	private function top_level_semantic_id( array $block, array $blueprint, array $nodes ): string {
		$metadata   = is_array( $block['attrs']['metadata'] ?? null ) ? $block['attrs']['metadata'] : array();
		$namespaced = is_array( $metadata['wpsuiteAgentComposer'] ?? null ) ? $metadata['wpsuiteAgentComposer'] : array();
		foreach ( array( $namespaced['nodeId'] ?? '', $metadata['name'] ?? '' ) as $candidate ) {
			$candidate = is_string( $candidate ) ? trim( $candidate ) : '';
			if ( isset( $nodes[ $candidate ] ) ) {
				return $candidate;
			}
		}
		if ( 'core/block' !== (string) ( $block['blockName'] ?? '' ) ) {
			return '';
		}
		$expanded = $this->synced_patterns->expand_blocks( array( $block ), $blueprint );
		if ( 1 !== count( $expanded ) || ! is_array( $expanded[0] ?? null ) ) {
			return '';
		}
		$expanded_metadata = is_array( $expanded[0]['attrs']['metadata'] ?? null ) ? $expanded[0]['attrs']['metadata'] : array();
		$candidate = trim( (string) ( $expanded_metadata['name'] ?? '' ) );
		return isset( $nodes[ $candidate ] ) ? $candidate : '';
	}

	private function set_pattern_override( array $blocks, string $field, mixed $value, bool $must_exist ): array {
		$matches = 0;
		$blocks = $this->walk_set_pattern_override( $blocks, $field, $value, $must_exist, $matches );
		if ( 1 !== $matches ) {
			throw new Execution_Exception( 'migration_field_ambiguous', 'A field migration operation must resolve to exactly one synced pattern instance.' );
		}
		return $blocks;
	}

	private function walk_set_pattern_override( array $blocks, string $field, mixed $value, bool $must_exist, int &$matches ): array {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			$content = is_array( $block['attrs']['content'] ?? null ) ? $block['attrs']['content'] : array();
			if ( 'core/block' === (string) $block['blockName'] && ( array_key_exists( $field, $content ) || ! $must_exist ) ) {
				$metadata   = is_array( $block['attrs']['metadata'] ?? null ) ? $block['attrs']['metadata'] : array();
				$namespaced = is_array( $metadata['wpsuiteAgentComposer'] ?? null ) ? $metadata['wpsuiteAgentComposer'] : array();
				$pattern    = (string) ( $namespaced['patternName'] ?? $metadata['patternName'] ?? '' );
				$definitions = (array) ( $this->config->get_design_policy()['synced_structural_patterns'][ $pattern ]['overrides'] ?? array() );
				if ( isset( $definitions[ $field ] ) ) {
					$attributes = (array) ( $definitions[ $field ]['attributes'] ?? array() );
					$content[ $field ] = is_array( $value ) ? $value : ( 1 === count( $attributes ) ? array( $attributes[0] => $value ) : $value );
					$block['attrs']['content'] = $content;
					$blocks[ $index ] = $block;
					++$matches;
				}
			}
			$children = (array) ( $block['innerBlocks'] ?? array() );
			if ( ! empty( $children ) ) {
				$block['innerBlocks'] = $this->walk_set_pattern_override( $children, $field, $value, $must_exist, $matches );
				$blocks[ $index ] = $block;
			}
		}
		return $blocks;
	}

	private function map_pattern_override( array $blocks, string $from, string $to ): array {
		$matches = 0;
		$blocks = $this->walk_map_pattern_override( $blocks, $from, $to, $matches );
		if ( 1 !== $matches ) {
			throw new Execution_Exception( 'migration_field_ambiguous', 'A map_field operation must resolve one exact stored Pattern Override.' );
		}
		return $blocks;
	}

	private function walk_map_pattern_override( array $blocks, string $from, string $to, int &$matches ): array {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			$content = is_array( $block['attrs']['content'] ?? null ) ? $block['attrs']['content'] : array();
			if ( 'core/block' === (string) $block['blockName'] && array_key_exists( $from, $content ) ) {
				if ( array_key_exists( $to, $content ) ) {
					throw new Execution_Exception( 'migration_field_target_exists', 'A map_field operation would overwrite an existing target override.' );
				}
				$content[ $to ] = $content[ $from ];
				unset( $content[ $from ] );
				$block['attrs']['content'] = $content;
				$blocks[ $index ] = $block;
				++$matches;
			}
			$children = (array) ( $block['innerBlocks'] ?? array() );
			if ( ! empty( $children ) ) {
				$block['innerBlocks'] = $this->walk_map_pattern_override( $children, $from, $to, $matches );
				$blocks[ $index ] = $block;
			}
		}
		return $blocks;
	}

	private function operation_report( array $operations, array $preserve_current_order ): array {
		return array_map(
			static function ( array $operation ) use ( $preserve_current_order ): array {
				$operation['status'] = 'move_section' === (string) $operation['type'] && isset( $preserve_current_order[ (string) $operation['id'] ] ) ? 'skipped-preserved-order' : 'applied';
				return $operation;
			},
			$operations
		);
	}

	private function baseline_for_response( string $page_type, array $baseline ): array {
		return array(
			'blueprint' => array( 'id' => $page_type, 'version' => (int) ( $baseline['blueprint_version'] ?? 0 ) ),
			'structure_contract' => $baseline['structure_contract'] ?? null,
		);
	}

	private function public_plan( array $plan ): array {
		unset( $plan['_target_content'], $plan['_target_metadata'] );
		return $plan;
	}

	private function time_token( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return '1970-01-01T00:00:00Z';
		}
		$timestamp = strtotime( $value . ( str_contains( $value, 'Z' ) || str_contains( $value, '+' ) ? '' : ' UTC' ) );
		return false === $timestamp ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}
}
