<?php

namespace SmartCloud\AgentComposer\Integration\Providers;

final class ProviderRegistry {
	public const PROFILE_FILTER = 'smartcloud_composer_provider_profiles';

	public function profiles(): array {
		$profiles = apply_filters( 'smartcloud_composer_provider_profiles', array() );
		$profiles = is_array( $profiles ) ? $profiles : array();
		$valid    = array();
		foreach ( $profiles as $profile ) {
			if ( ! is_array( $profile ) || ! $this->valid_profile( $profile ) ) {
				continue;
			}
			$name = (string) $profile['ability']['name'];
			if ( function_exists( 'wp_has_ability' ) && ! wp_has_ability( $name ) ) {
				continue;
			}
			$valid[ $name ] = $profile;
		}
		ksort( $valid );
		return array_values( $valid );
	}

	private function valid_profile( array $profile ): bool {
		return '1.0.0-rc.1' === ( $profile['schema_version'] ?? null )
			&& is_array( $profile['provider'] ?? null )
			&& is_array( $profile['ability'] ?? null )
			&& is_array( $profile['composer'] ?? null )
			&& preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', (string) ( $profile['ability']['name'] ?? '' ) )
			&& true === ( $profile['composer']['agent_draft_safe'] ?? false );
	}
}
