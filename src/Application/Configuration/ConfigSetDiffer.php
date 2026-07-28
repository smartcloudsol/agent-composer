<?php

namespace SmartCloud\AgentComposer\Application\Configuration;

use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;

final class ConfigSetDiffer {
	public function __construct( private readonly WordPressConfigurationRepository $repository ) {}

	public function diff( string $from, string $to ): array {
		$before  = $this->index( $from );
		$after   = $this->index( $to );
		$keys    = array_values( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) );
		sort( $keys );
		$added   = array();
		$removed = array();
		$changed = array();
		foreach ( $keys as $key ) {
			if ( ! isset( $before[ $key ] ) ) {
				$added[] = $after[ $key ];
			} elseif ( ! isset( $after[ $key ] ) ) {
				$removed[] = $before[ $key ];
			} elseif ( $before[ $key ]['content_hash'] !== $after[ $key ]['content_hash'] ) {
				$changed[] = array( 'identity' => $key, 'before' => $before[ $key ], 'after' => $after[ $key ] );
			}
		}
		return array(
			'from'    => $from,
			'to'      => $to,
			'added'   => $added,
			'removed' => $removed,
			'changed' => $changed,
			'summary' => array( 'added' => count( $added ), 'removed' => count( $removed ), 'changed' => count( $changed ) ),
		);
	}

	private function index( string $config_set ): array {
		$index = array();
		foreach ( $this->repository->entities( $config_set ) as $post ) {
			$entity = $this->repository->describe_entity( $post );
			$index[ $entity['type'] . ':' . $entity['key'] ] = $entity;
		}
		return $index;
	}
}
