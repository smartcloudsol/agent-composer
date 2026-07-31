<?php

namespace SmartCloud\AgentComposer\Application\Configuration;

use InvalidArgumentException;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;

final class ConfigSetActivator {
	private const LOCK_OPTION = 'smartcloud_composer_activation_lock';

	public function __construct(
		private readonly WordPressConfigurationRepository $repository,
		private readonly ValidationReceiptService $receipts,
		private readonly AuditTable $audit
	) {}

	public function activate( string $config_set, string $validation_receipt, string $operation = 'activate' ): array {
		$config_set = sanitize_key( $config_set );
		if ( '' === $config_set || ! preg_match( '/^sha256:[a-f0-9]{64}$/', $validation_receipt ) ) {
			throw new InvalidArgumentException( 'A valid config set and validation receipt are required.' );
		}
		$lock = $this->acquire_lock();
		try {
			$config_hash = $this->repository->configuration_hash( $config_set );
			$this->receipts->assert_valid( $validation_receipt, $config_set, $config_hash );
			$entities = $this->repository->entities( $config_set );
			if ( array() === $entities ) {
				throw new InvalidArgumentException( 'The requested working set does not exist.' );
			}

			$previous = (string) get_option( 'smartcloud_composer_active_config_set', '' );
			$this->repository->set_active( $config_set, true );
			$this->repository->set_lifecycle( $config_set, 'active' );
			update_option( 'smartcloud_composer_previous_config_set', $previous, false );
			update_option( 'smartcloud_composer_activation_receipt', $validation_receipt, false );
			update_option(
				'smartcloud_composer_active_snapshot',
				array( 'config_set' => $config_set, 'config_hash' => $config_hash, 'activated_gmt' => gmdate( 'c' ), 'activated_by' => get_current_user_id() ),
				false
			);
			// This option is the authoritative atomic cutover. The previous set stays readable until this pointer changes.
			update_option( 'smartcloud_composer_active_config_set', $config_set, true );
			if ( '' !== $previous && $previous !== $config_set ) {
				$this->repository->set_active( $previous, false );
				$this->repository->set_lifecycle( $previous, 'archived' );
			}
			$this->receipts->consume( $validation_receipt );
			$event = 'rollback' === $operation ? 'config-set-rolled-back' : 'config-set-activated';
			$this->audit->record( $event, 'success', array( 'config_set' => $config_set, 'previous' => $previous, 'config_hash' => $config_hash, 'validation_receipt' => $validation_receipt ) );
			return array( 'active' => $config_set, 'previous' => $previous, 'config_hash' => $config_hash, 'operation' => $operation );
		} finally {
			$this->release_lock( $lock );
		}
	}

	/** @return array{active:string,deactivated:string,config_hash:string} */
	public function deactivate( string $config_set, string $confirmation, string $expected_hash ): array {
		$config_set = sanitize_key( $config_set );
		if ( '' === $config_set || ! hash_equals( $config_set, $confirmation ) ) {
			throw new InvalidArgumentException( 'Type the complete active Config Set ID to confirm deactivation.' );
		}
		if ( $config_set !== (string) get_option( 'smartcloud_composer_active_config_set', '' ) ) {
			throw new InvalidArgumentException( 'Only the currently active Config Set can be deactivated.' );
		}
		if ( ! preg_match( '/^sha256:[a-f0-9]{64}$/', $expected_hash ) ) {
			throw new InvalidArgumentException( 'A valid Config Set hash is required for deactivation.' );
		}

		$lock = $this->acquire_lock();
		try {
			if ( $config_set !== (string) get_option( 'smartcloud_composer_active_config_set', '' ) ) {
				throw new InvalidArgumentException( 'The active Config Set changed while deactivation was being confirmed. Review it again.' );
			}
			$current_hash = $this->repository->configuration_hash( $config_set );
			if ( ! hash_equals( $expected_hash, $current_hash ) ) {
				throw new InvalidArgumentException( 'The active Config Set changed after the deactivation dialog opened. Review it again.' );
			}
			$this->repository->set_active( $config_set, false );
			$this->repository->set_lifecycle( $config_set, 'archived' );
			delete_option( 'smartcloud_composer_active_config_set' );
			delete_option( 'smartcloud_composer_previous_config_set' );
			delete_option( 'smartcloud_composer_activation_receipt' );
			delete_option( 'smartcloud_composer_active_snapshot' );
			$this->audit->record( 'config-set-deactivated', 'success', array( 'config_set' => $config_set, 'config_hash' => $current_hash ) );
			return array( 'active' => '', 'deactivated' => $config_set, 'config_hash' => $current_hash );
		} finally {
			$this->release_lock( $lock );
		}
	}

	private function acquire_lock(): string {
		$token   = wp_generate_uuid4();
		$payload = array( 'token' => $token, 'expires' => time() + 30 );
		if ( add_option( self::LOCK_OPTION, $payload, '', false ) ) {
			return $token;
		}
		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && (int) ( $current['expires'] ?? 0 ) < time() ) {
			delete_option( self::LOCK_OPTION );
			if ( add_option( self::LOCK_OPTION, $payload, '', false ) ) {
				return $token;
			}
		}
		throw new InvalidArgumentException( 'Another configuration activation is already in progress.' );
	}

	private function release_lock( string $token ): void {
		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && hash_equals( $token, (string) ( $current['token'] ?? '' ) ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}
}
