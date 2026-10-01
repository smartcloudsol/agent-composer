<?php

declare(strict_types=1);

namespace {
	function absint( mixed $value ): int { return abs( (int) $value ); }
	function sanitize_key( string $value ): string { return strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
}

namespace SmartCloud\AgentComposer\Infrastructure\Persistence {
	final class AuditTable {}
}

namespace SmartCloud\AgentComposer\Execution {
	final class Pattern_Assembler {}
	final class Page_Validator {}
	final class Target_Resolver {}
	final class Localization_Provider_Registry {}
	final class Draft_Service {
		public const PAGE_TYPE_META = '_wpsuite_agent_page_type';
		public const OWNED_META = '_wpsuite_agent_owned';
	}
	final class Managed_Document_State { public const MANAGED_META = '_composer_managed_document'; }
	final class Config_Repository {
		public string $mode = 'required';
		public function get_admin_creation_policy( string $post_type ): array {
			return 'vizsgalatok' === $post_type
				? array( 'mode' => $this->mode, 'default_page_type' => 'examination' )
				: array( 'mode' => 'off', 'default_page_type' => '' );
		}
		public function get_blueprint( string $page_type ): array {
			return match ( $page_type ) {
				'examination', 'examination-legacy-media' => array( 'target_post_type' => 'vizsgalatok' ),
				'foreign-blueprint' => array( 'target_post_type' => 'other-type' ),
				default => throw new \RuntimeException( 'unknown blueprint' ),
			};
		}
	}

	require_once dirname( __DIR__ ) . '/src/Execution/Admin_Managed_Document_Service.php';

	$failures = array();
	$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
		if ( ! $condition ) {
			$failures[] = $message;
		}
	};

	$config = new Config_Repository();
	$service = new Admin_Managed_Document_Service(
		$config,
		new Pattern_Assembler(),
		new Page_Validator(),
		new Target_Resolver(),
		new Managed_Document_State(),
		new Localization_Provider_Registry(),
		new \SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable()
	);

	$assert(
		$service->prevent_unmanaged_creation( false, array( 'post_type' => 'vizsgalatok' ) ),
		'A required governed CPT must reject a blank or out-of-band unmanaged insert.'
	);
	$assert(
		! $service->prevent_unmanaged_creation(
			false,
			array(
				'post_type' => 'vizsgalatok',
				'meta_input' => array(
					Managed_Document_State::MANAGED_META => '1',
					Draft_Service::PAGE_TYPE_META => 'examination',
				),
			)
		),
		'A correctly marked canonical bootstrap must be allowed through the insert boundary.'
	);
	$assert(
		$service->prevent_unmanaged_creation(
			false,
			array(
				'post_type' => 'vizsgalatok',
				'meta_input' => array(
					Managed_Document_State::MANAGED_META => '1',
					Draft_Service::PAGE_TYPE_META => 'other-blueprint',
				),
			)
		),
		'A managed marker must not authorize a Blueprint assigned to another creation rule.'
	);
	$assert(
		! $service->prevent_unmanaged_creation(
			false,
			array(
				'post_type' => 'vizsgalatok',
				'meta_input' => array(
					Managed_Document_State::MANAGED_META => '1',
					Draft_Service::OWNED_META => '1',
					Draft_Service::PAGE_TYPE_META => 'examination-legacy-media',
				),
			)
		),
		'A Composer-owned proposal may use a non-default Blueprint that targets the same governed post type.'
	);
	$assert(
		$service->prevent_unmanaged_creation(
			false,
			array(
				'post_type' => 'vizsgalatok',
				'meta_input' => array(
					Managed_Document_State::MANAGED_META => '1',
					Draft_Service::OWNED_META => '1',
					Draft_Service::PAGE_TYPE_META => 'foreign-blueprint',
				),
			)
		),
		'A Composer-owned marker must not authorize a Blueprint targeting another post type.'
	);
	$config->mode = 'optional';
	$assert(
		! $service->prevent_unmanaged_creation( false, array( 'post_type' => 'vizsgalatok' ) ),
		'Optional governance must preserve ordinary WordPress creation.'
	);
	$assert(
		! $service->prevent_unmanaged_creation( false, array( 'ID' => 42, 'post_type' => 'vizsgalatok' ) ),
		'Creation policy must not block updates to existing legacy records.'
	);

	if ( $failures ) {
		fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
		exit( 1 );
	}

	echo "admin-managed-document: ok\n";
}
