<?php
namespace SmartCloud\AgentComposer\Security;

final class DeploymentCandidate {
    public static function register( array $adapters ): array {
        $adapters['composer'] = array( 'sections' => array( self::class, 'sections' ), 'apply' => array( self::class, 'apply' ) );
        return $adapters;
    }
    public static function sections( array $fields ): array {
        if ( ! current_user_can( 'manage_options' ) ) { return array(); }
        $settings = new McpSecuritySettings();
        $current = $settings->get();
        $identity = $settings->identity_configuration( $current );
        $client = $fields['clientId'] ?? null;
        $resource = $fields['resourceUri'] ?? null;
        $valid = is_string( $client ) && preg_match( '/^[a-z0-9]{1,128}$/D', $client ) && is_string( $resource ) && $resource !== '';
        $probe = $current;
        $probe['oauth_resource_uri'] = is_string( $resource ) ? $resource : '';
        $valid = $valid && $settings->normalize( $probe )['oauth_resource_uri'] === $resource;
        $matching = null;
        foreach ( $current['clients'] as $entry ) { if ( $entry['client_id'] === $client ) { $matching = $entry; break; } }
        $rows = array();
        if ( null === $matching ) { $rows[] = array( 'key' => 'clientId', 'label' => __( 'OAuth client (Reader ceiling)', 'smartcloud-agent-composer' ), 'current' => '', 'proposed' => $client ); }
        if ( $current['oauth_resource_uri'] !== $resource ) { $rows[] = array( 'key' => 'resourceUri', 'label' => __( 'OAuth resource URI', 'smartcloud-agent-composer' ), 'current' => $current['oauth_resource_uri'], 'proposed' => $resource ); }
        if ( ! $rows && ( $identity['region'] !== ( $fields['region'] ?? null ) || $identity['user_pool_id'] !== ( $fields['userPoolId'] ?? null ) ) ) {
            $rows[] = array( 'key' => 'userPoolId', 'label' => __( 'Effective user pool', 'smartcloud-agent-composer' ), 'current' => $identity['user_pool_id'], 'proposed' => $fields['userPoolId'] ?? '' );
        }
        if ( ! $rows ) { return array(); }
        $section = array( 'title' => __( 'Composer MCP access: allow Reader client', 'smartcloud-agent-composer' ), 'rows' => $rows, 'defaultSelected' => false );
        if ( ! $valid || $identity['region'] !== ( $fields['region'] ?? null ) || $identity['user_pool_id'] !== ( $fields['userPoolId'] ?? null ) || ( $current['oauth_resource_uri'] !== '' && $current['oauth_resource_uri'] !== $resource ) ) {
            $section['blocked'] = __( 'Review identity and resource URI in Composer MCP Access settings before applying this recommendation.', 'smartcloud-agent-composer' );
        }
        return array( 'access' => $section );
    }
    public static function apply( string $section, array $fields ): bool|\WP_Error {
        if ( ! current_user_can( 'manage_options' ) || 'access' !== $section ) { return new \WP_Error( 'composer_candidate_forbidden', __( 'Cannot apply this recommendation.', 'smartcloud-agent-composer' ) ); }
        $sections = self::sections( $fields );
        if ( empty( $sections['access'] ) || isset( $sections['access']['blocked'] ) ) { return new \WP_Error( 'composer_candidate_blocked', __( 'Review MCP Access settings first.', 'smartcloud-agent-composer' ) ); }
        $settings = new McpSecuritySettings();
        $current = $settings->get();
        $current['oauth_resource_uri'] = $fields['resourceUri'];
        $found = false;
        foreach ( $current['clients'] as $entry ) { if ( $entry['client_id'] === $fields['clientId'] ) { $found = true; break; } }
        if ( ! $found ) { $current['clients'][] = array( 'label' => $fields['clientId'], 'client_id' => $fields['clientId'], 'role_ceiling' => 'reader', 'scopes' => array( 'composer.read' ) ); }
        $settings->save( $current );
        return true;
    }
}
