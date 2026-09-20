<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

use SmartCloud\AgentComposer\Infrastructure\WordPress\Activation;

/** Bounded bulk orchestration over the exact single-document migration boundary. */
final class Bulk_Blueprint_Migration_Service {
	private const MAX_PLAN_ITEMS   = 100;
	private const MAX_CREATE_ITEMS = 25;

	public function __construct(
		private readonly Config_Repository $config,
		private readonly Blueprint_Migration_Service $migrations,
		private readonly Localization_Provider_Registry $localization
	) {}

	/** Classify one bounded page of matching managed published documents without writes. */
	public function plan( array $input ): array {
		$this->assert_permission();
		$page_type    = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$migration_id = sanitize_key( (string) ( $input['migration_id'] ?? '' ) );
		$page          = min( 100000, max( 1, absint( $input['page'] ?? 1 ) ) );
		$per_page      = min( self::MAX_PLAN_ITEMS, max( 1, absint( $input['per_page'] ?? 25 ) ) );
		$route         = $this->route( $page_type, $migration_id );
		$blueprint     = $this->config->get_blueprint( $page_type );
		$query         = new \WP_Query(
			array(
				'post_type'              => (string) $blueprint['target_post_type'],
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => $this->source_meta_query( $page_type, (array) $route['from'] ),
			)
		);

		$items  = array();
		$counts = array( 'automatic' => 0, 'review_required' => 0, 'incompatible' => 0 );
		foreach ( array_map( 'absint', (array) $query->posts ) as $post_id ) {
			try {
				$preview        = $this->migrations->preview( array( 'post_id' => $post_id, 'page_type' => $page_type, 'migration_id' => $migration_id ) );
				$classification = $this->classify_preview( $preview );
				++$counts[ $classification ];
				$item = array(
					'post_id'                      => $post_id,
					'classification'                => $classification,
					'proposal_eligible'              => true === ( $preview['proposal_eligible'] ?? false ),
					'plan_hash'                     => (string) ( $preview['plan_hash'] ?? '' ),
					'expected_modified_gmt'         => (string) ( $preview['source_modified_gmt'] ?? '' ),
					'expected_content_hash'         => (string) ( $preview['source_content_hash'] ?? '' ),
					'expected_migration_plan_hash'  => (string) ( $preview['plan_hash'] ?? '' ),
					'target_content_hash'           => (string) ( $preview['target_content_hash'] ?? '' ),
					'operations'                    => (array) ( $preview['operations'] ?? array() ),
					'override_rebase'               => (array) ( $preview['override_rebase'] ?? array() ),
					'validation'                    => (array) ( $preview['validation'] ?? array() ),
				);
				if ( 'automatic' === $classification ) {
					$post    = get_post( $post_id );
					$context = $post instanceof \WP_Post ? $this->localization->resolve_for_blueprint( $post_id, (string) $post->post_type, $blueprint ) : array();
					$item['content_language'] = trim( (string) ( $context['content_language'] ?? $blueprint['content_language'] ?? '' ) );
				}
				$items[] = $item;
			} catch ( Execution_Exception $error ) {
				++$counts['incompatible'];
				$items[] = array(
					'post_id'       => $post_id,
					'classification' => 'incompatible',
					'proposal_eligible' => false,
					'error'         => array(
						'code'    => $error->get_execution_code(),
						'message' => $error->getMessage(),
						'details' => $error->get_execution_data(),
					),
				);
			}
		}

		$total      = (int) $query->found_posts;
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		return array(
			'schema_version'   => 1,
			'page_type'       => $page_type,
			'migration_id'    => $migration_id,
			'from_baseline'   => (array) $route['from'],
			'to_baseline'     => (array) $route['to'],
			'page'            => $page,
			'per_page'        => $per_page,
			'total_candidates' => $total,
			'total_pages'     => $total_pages,
			'has_more'        => $page < $total_pages,
			'batch_counts'     => $counts,
			'items'            => $items,
			'next_ability'     => 'smartcloud-agent-composer/create-blueprint-migration-proposals',
		);
	}

	/** Create proposals for an explicitly reviewed bounded list; failures remain item-local and retryable. */
	public function create_proposals( array $input ): array {
		$this->assert_permission();
		if ( true !== ( $input['confirm_bulk_proposals'] ?? false ) ) {
			throw new Execution_Exception( 'bulk_migration_confirmation_required', 'Bulk proposal creation requires confirm_bulk_proposals=true.' );
		}
		$page_type    = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$migration_id = sanitize_key( (string) ( $input['migration_id'] ?? '' ) );
		$this->route( $page_type, $migration_id );
		$requested = is_array( $input['items'] ?? null ) ? array_values( $input['items'] ) : array();
		if ( empty( $requested ) || count( $requested ) > self::MAX_CREATE_ITEMS ) {
			throw new Execution_Exception( 'bulk_migration_items_invalid', 'Bulk proposal creation requires between 1 and 25 reviewed items.' );
		}
		$seen    = array();
		$results = array();
		$counts  = array( 'created' => 0, 'idempotent' => 0, 'failed' => 0 );
		foreach ( $requested as $item ) {
			if ( ! is_array( $item ) ) {
				throw new Execution_Exception( 'bulk_migration_item_invalid', 'Every bulk migration item must be an object returned from a reviewed plan.' );
			}
			$post_id = absint( $item['post_id'] ?? 0 );
			if ( $post_id < 1 || isset( $seen[ $post_id ] ) ) {
				throw new Execution_Exception( 'bulk_migration_item_duplicate', 'Every bulk migration item must identify a distinct positive post_id.' );
			}
			$seen[ $post_id ] = true;
			try {
				$result = $this->migrations->create_proposal(
					array(
						'post_id'                       => $post_id,
						'page_type'                     => $page_type,
						'migration_id'                  => $migration_id,
						'content_language'              => (string) ( $item['content_language'] ?? '' ),
						'expected_modified_gmt'         => (string) ( $item['expected_modified_gmt'] ?? '' ),
						'expected_content_hash'         => (string) ( $item['expected_content_hash'] ?? '' ),
						'expected_migration_plan_hash'  => (string) ( $item['expected_migration_plan_hash'] ?? '' ),
						'idempotency_key'               => (string) ( $item['idempotency_key'] ?? '' ),
						'confirm_proposal'              => true,
					)
				);
				$outcome = true === ( $result['idempotent_replay'] ?? false ) ? 'idempotent' : 'created';
				++$counts[ $outcome ];
				$results[] = array( 'post_id' => $post_id, 'outcome' => $outcome, 'proposal' => $result );
			} catch ( Execution_Exception $error ) {
				++$counts['failed'];
				$results[] = array(
					'post_id' => $post_id,
					'outcome' => 'failed',
					'error'   => array( 'code' => $error->get_execution_code(), 'message' => $error->getMessage(), 'details' => $error->get_execution_data() ),
				);
			}
		}
		return array(
			'schema_version' => 1,
			'page_type'     => $page_type,
			'migration_id'  => $migration_id,
			'counts'        => $counts,
			'items'         => $results,
			'partial_success' => $counts['failed'] > 0 && ( $counts['created'] + $counts['idempotent'] ) > 0,
			'publication_changed' => false,
		);
	}

	private function route( string $page_type, string $migration_id ): array {
		$route = $this->config->get_structure_migrations()[ $migration_id ] ?? null;
		if ( ! is_array( $route ) || $page_type !== (string) ( $route['blueprint'] ?? '' ) ) {
			throw new Execution_Exception( 'migration_not_found', 'No exact registered Structure migration exists for this Blueprint.' );
		}
		$blueprint = $this->config->get_blueprint( $page_type );
		$target    = (array) ( $route['to'] ?? array() );
		if (
			(int) ( $blueprint['schema_version'] ?? 0 ) !== (int) ( $target['blueprint_version'] ?? 0 )
			|| ( $blueprint['structure_contract'] ?? null ) !== ( $target['structure_contract'] ?? null )
		) {
			throw new Execution_Exception( 'migration_target_version_mismatch', 'The active Blueprint and Structure Contract do not match the bulk migration target.' );
		}
		return $route;
	}

	private function source_meta_query( string $page_type, array $from ): array {
		$contract = (array) ( $from['structure_contract'] ?? array() );
		return array(
			'relation' => 'AND',
			array( 'key' => Managed_Document_State::BLUEPRINT_META, 'value' => $page_type, 'compare' => '=' ),
			array( 'key' => Managed_Document_State::BLUEPRINT_VERSION_META, 'value' => (int) ( $from['blueprint_version'] ?? 0 ), 'compare' => '=', 'type' => 'NUMERIC' ),
			array( 'key' => Managed_Document_State::STRUCTURE_CONTRACT_META, 'value' => (string) ( $contract['id'] ?? '' ), 'compare' => '=' ),
			array( 'key' => Managed_Document_State::STRUCTURE_CONTRACT_VERSION_META, 'value' => (int) ( $contract['version'] ?? 0 ), 'compare' => '=', 'type' => 'NUMERIC' ),
		);
	}

	private function classify_preview( array $preview ): string {
		if ( true === ( $preview['proposal_eligible'] ?? false ) ) {
			return 'automatic';
		}
		$report = is_array( $preview['override_rebase'] ?? null ) ? $preview['override_rebase'] : array();
		if ( ! empty( $report['review_required'] ) || ! empty( $report['detached'] ) ) {
			return 'review_required';
		}
		return 'incompatible';
	}

	private function assert_permission(): void {
		if ( ! current_user_can( Activation::CAP_RUN_MIGRATIONS ) ) {
			throw new Execution_Exception( 'migration_permission_denied', 'The current user cannot run governed Blueprint migrations.' );
		}
	}
}
