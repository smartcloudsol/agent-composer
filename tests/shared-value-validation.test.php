<?php
declare(strict_types=1);

namespace SmartCloud\AgentComposer\Infrastructure\Persistence {
	class AuditTable { public function record(mixed ...$args): void {} }
}
namespace SmartCloud\AgentComposer\Infrastructure\WordPress {
	class Activation { public const CAP_MANAGE_STRUCTURE = 'manage_structure'; }
}
namespace SmartCloud\AgentComposer\Execution {
	class Config_Repository {
		public array $blueprint = array('excerpt_policy' => 'required', 'published_update_policy' => 'disabled');
		public function get_blueprint(string $page_type): array { return $this->blueprint; }
		public function get_design_policy(): array { return array('post_type_contract' => array('post')); }
	}
	class Page_Validator {
		public int $calls = 0;
		public function validate(string $type, string $content, ?string $previous = null, array $options = array()): array {
			++$this->calls;
			return array('valid' => 'broken' !== $content, 'errors' => 'broken' === $content ? array(array('code' => 'invalid_body')) : array());
		}
		public function refresh_native_pattern_revisions(string $type, string $content): array { return array('content' => str_replace('stale-body', 'refreshed-body', $content), 'operations' => array()); }
	}
	class Managed_Document_State {
		public function is_managed(int $id): bool { return 99 !== $id; }
		public function public_state(int $id): array { return array('status' => 'CURRENT'); }
		public function persist(int $id, string $type, array $validation): array { ++$GLOBALS['state_writes']; return array('status' => 'CURRENT'); }
	}
}
namespace {
	define('LOGGED_IN_COOKIE', 'wordpress_logged_in_test');
	$skip_boundary = isset($argv[1]) && preg_match('/^--skip=(WP_CLI|REST_REQUEST|DOING_CRON|DOING_AUTOSAVE)$/D', $argv[1], $skip_match);
	if ($skip_boundary) { define($skip_match[1], true); }
	class NativeSaveRejected extends RuntimeException {}
	class WP_Post {
		public function __construct(public int $ID, public string $post_type = 'post', public string $post_status = 'draft', public string $post_title = 'Example title', public string $post_excerpt = '', public string $post_content = 'valid') {}
	}
	class WP_Term {
		public function __construct(public int $term_id, public string $slug, public string $taxonomy = 'post_tag') {}
	}
	class WP_Post_Type { public object $cap; public function __construct() { $this->cap = (object) array('edit_published_posts' => 'edit_published_posts'); } }
	class WP_Error {
		public function __construct(public string $code, public string $message, public array $data = array()) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
	class WP_REST_Request {
		public function __construct(public array $params) {}
		public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
		public function get_header(string $key): string { return ''; }
	}
	function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
	function sanitize_textarea_field(string $value): string { return trim(strip_tags($value)); }
	function wp_strip_all_tags(string $value, bool $remove_breaks = false): string { return strip_tags($value); }
	function sanitize_title(string $value): string { return trim(strtolower((string) preg_replace('/[^a-z0-9-]+/i', '-', $value)), '-'); }
	function sanitize_key(string $value): string { return $value; }
	function absint(mixed $value): int { return abs((int) $value); }
	function get_post(int $id): mixed { return $GLOBALS['posts'][$id] ?? null; }
	function get_post_meta(int $id, string $key, bool $single = true): mixed { return $GLOBALS['meta'][$id][$key] ?? ''; }
	function get_term(int $id, string $taxonomy): mixed {
		$term = $GLOBALS['terms'][$id] ?? null;
		return $term instanceof WP_Term && $term->taxonomy === $taxonomy ? $term : null;
	}
	function get_term_by(string $field, string $slug, string $taxonomy): mixed {
		foreach ($GLOBALS['terms'] as $term) { if ($term->slug === $slug && $term->taxonomy === $taxonomy) { return $term; } }
		return null;
	}
	function get_taxonomy(string $taxonomy): object { return (object) array('hierarchical' => 'category' === $taxonomy); }
	function current_user_can(string $capability, mixed ...$args): bool { return $GLOBALS['native_can_edit'] ?? false; }
	function is_admin(): bool { return $GLOBALS['is_admin'] ?? false; }
	function wp_doing_ajax(): bool { return $GLOBALS['ajax'] ?? false; }
	function wp_is_post_revision(int $id): bool { return $GLOBALS['revision'] ?? false; }
	function wp_is_post_autosave(int $id): bool { return $GLOBALS['autosave'] ?? false; }
	function get_current_user_id(): int { return 7; }
	function wp_validate_auth_cookie(string $cookie, string $scheme): int|false { return 'valid-cookie' === $cookie && 'logged_in' === $scheme ? 7 : false; }
	function wp_verify_nonce(string $nonce, string $action): int|false { return $nonce === 'nonce:' . $action ? 1 : false; }
	function get_post_type_object(string $type): WP_Post_Type { return new WP_Post_Type(); }
	function wp_unslash(mixed $value): mixed { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value); }
	function wp_slash(string $value): string { return addslashes($value); }
	function esc_html(string $value): string { return htmlspecialchars($value); }
	function wp_die(string $message, string $title, array $args): never { throw new NativeSaveRejected($title); }
	function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): void { $GLOBALS['hooks'][$hook][] = $callback; }
	function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): void { add_filter($hook, $callback, $priority, $args); }
	function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
		foreach ($GLOBALS['hooks'][$hook] ?? array() as $callback) { $value = $callback($value, ...$args); }
		return $value;
	}

	$root = dirname(__DIR__) . '/src/Execution/';
	foreach (array('Execution_Exception', 'Excerpt_Policy', 'Content_Language_Validator', 'Editorial_Field_Validator', 'Relation_Value_Validator', 'Taxonomy_Value_Validator', 'Term_Definition_Validator', 'Draft_Service', 'Structure_Contract_Save_Guard') as $file) { require_once $root . $file . '.php'; }
	require_once dirname(__DIR__) . '/src/Security/ActorContext.php';
	require_once dirname(__DIR__) . '/src/Security/ActorIdentity.php';
	if ($skip_boundary) {
		$GLOBALS['is_admin'] = true;
		$_POST = $_REQUEST = array('action' => 'editpost', 'post_ID' => 1, '_wpnonce' => 'invalid');
		$skip_guard = (new ReflectionClass(SmartCloud\AgentComposer\Execution\Structure_Contract_Save_Guard::class))->newInstanceWithoutConstructor();
		$route = (new ReflectionMethod(SmartCloud\AgentComposer\Execution\Structure_Contract_Save_Guard::class, 'admin_route'))->invoke($skip_guard, 1);
		if (null !== $route) { throw new RuntimeException('Internal boundary not skipped: ' . $skip_match[1]); }
		echo 'native-admin-skip: ' . $skip_match[1] . " ok\n";
		exit;
	}

	use SmartCloud\AgentComposer\Execution\{Config_Repository, Content_Language_Validator, Editorial_Field_Validator, Relation_Value_Validator, Taxonomy_Value_Validator, Term_Definition_Validator, Draft_Service, Page_Validator, Managed_Document_State, Structure_Contract_Save_Guard, Execution_Exception};

	function same(mixed $expected, mixed $actual, string $message): void {
		if ($expected !== $actual) { throw new RuntimeException($message . ': ' . var_export($actual, true)); }
	}
	function rejection(string $code, callable $callback): void {
		try { $callback(); } catch (Execution_Exception $error) { same($code, $error->get_execution_code(), 'Shared rejection'); return; }
		throw new RuntimeException('Missing shared rejection: ' . $code);
	}
	$GLOBALS['posts'] = array(1 => new WP_Post(1), 2 => new WP_Post(2, 'service', 'publish'), 3 => new WP_Post(3, 'service', 'draft'));
	$GLOBALS['terms'] = array(1 => new WP_Term(1, 'topic'), 2 => new WP_Term(2, 'parent', 'category'));
	$relation = array('semantic_type' => 'relation', 'cardinality' => 'many', 'maximum_items' => 2, 'target_post_types' => array('service'), 'target_post_statuses' => array('publish'));
	Relation_Value_Validator::assert_value(array(), $relation, 'related');
	Relation_Value_Validator::assert_value(array(2), $relation, 'related');
	foreach (array('relation_self_reference' => array(array(2), array(2)), 'relation_duplicate_target' => array(array(2, 2), array()), 'relation_target_id_invalid' => array(array('2'), array()), 'relation_target_missing' => array(array(99), array()), 'relation_target_type_mismatch' => array(array(1), array()), 'relation_target_status_mismatch' => array(array(3), array())) as $code => [$values, $excluded]) {
		rejection($code, fn() => Relation_Value_Validator::assert_value($values, $relation, 'related', $excluded));
	}
	rejection('relation_self_reference', fn() => Relation_Value_Validator::assert_value(array(2), $relation, 'related', array(1, 2)));
	same(array(), Taxonomy_Value_Validator::assert_assignment(array(), 'post_tag', array('maximum_items' => 1)), 'Empty replacement');
	same(array(1), Taxonomy_Value_Validator::assert_assignment(array(1), 'post_tag', array('maximum_items' => 1)), 'Valid assignment');
	rejection('taxonomy_term_ids_invalid', fn() => Taxonomy_Value_Validator::assert_assignment(array('1'), 'post_tag', array()));
	rejection('taxonomy_term_ids_duplicate', fn() => Taxonomy_Value_Validator::assert_assignment(array(1, 1), 'post_tag', array()));
	rejection('taxonomy_term_limit_exceeded', fn() => Taxonomy_Value_Validator::assert_assignment(array(1, 2), 'post_tag', array('maximum_items' => 1)));
	rejection('taxonomy_term_not_found', fn() => Taxonomy_Value_Validator::assert_assignment(array(2), 'post_tag', array()));

	$config = new Config_Repository();
	$language = new Content_Language_Validator($config);
	$editorial = new Editorial_Field_Validator($config, $language);
	$excerpt = str_repeat('Example public description. ', 4);
	$seo = str_repeat('Useful information. ', 7);
	$editorial->sanitize_fields(array('excerpt' => $excerpt, 'meta_description' => $seo), 'article');
	rejection('invalid_title', fn() => Editorial_Field_Validator::sanitize_title(''));
	rejection('invalid_excerpt_length', fn() => $editorial->sanitize_fields(array('excerpt' => 'short', 'meta_description' => $seo), 'article'));
	rejection('invalid_seo_metadata', fn() => $editorial->sanitize_fields(array('excerpt' => $excerpt, 'meta_description' => ''), 'article'));
	$config->blueprint['seo_contract']['meta_description'] = array('minimum_characters' => 10, 'maximum_characters' => 20);
	$editorial->sanitize_fields(array('excerpt' => $excerpt, 'meta_description' => 'Custom SEO rule'), 'article');
	same(true, $editorial->stored_errors($excerpt, 'Custom SEO rule', 'article')['valid'], 'Blueprint SEO override parity');
	unset($config->blueprint['seo_contract']);
	$draft = (new ReflectionClass(Draft_Service::class))->newInstanceWithoutConstructor();
	foreach (array('config' => $config, 'language' => $language) as $property => $value) {
		(new ReflectionProperty(Draft_Service::class, $property))->setValue($draft, $value);
	}
	$draft_editorial = new ReflectionMethod(Draft_Service::class, 'sanitize_editorial_fields');
	rejection('invalid_excerpt_length', fn() => $draft_editorial->invoke($draft, array('excerpt' => 'short', 'meta_description' => $seo), 'article'));
	rejection('invalid_seo_metadata', fn() => $draft_editorial->invoke($draft, array('excerpt' => $excerpt, 'meta_description' => ''), 'article'));
	same($editorial->sanitize_fields(array('excerpt' => $excerpt, 'meta_description' => $seo), 'article'), $draft_editorial->invoke($draft, array('excerpt' => $excerpt, 'meta_description' => $seo), 'article'), 'Agent and native shared editorial output');

	$term_validator = new Term_Definition_Validator($language);
	$new = array('name' => 'Example topic', 'slug' => 'example-topic', 'description' => 'A standalone explanation of the public topic.');
	same('example-topic', $term_validator->validate($new, 'post_tag', array(), $config->blueprint)['slug'], 'New public term');
	rejection('taxonomy_term_description_invalid', fn() => $term_validator->validate(array_merge($new, array('description' => '')), 'post_tag', array(), $config->blueprint));
	$stored = array('name' => 'Old topic', 'slug' => 'old-topic', 'description' => '', 'parent' => 0);
	same('', $term_validator->validate(array('name' => 'Renamed topic'), 'post_tag', array(), $config->blueprint, $stored)['description'], 'Unchanged legacy description retained');
	same('old-topic', $term_validator->validate(array('name' => 'Renamed topic'), 'post_tag', array(), $config->blueprint, $stored)['slug'], 'Omitted native slug retained');
	rejection('taxonomy_term_slug_invalid', fn() => $term_validator->validate(array_merge($new, array('slug' => 'Not Canonical')), 'post_tag', array(), $config->blueprint));
	$parent_rule = array('creation_parent_policy' => 'allowlist', 'creation_parent_slugs' => array('parent'));
	same(2, $term_validator->validate(array_merge($new, array('parent' => 2)), 'category', $parent_rule, $config->blueprint)['parent'], 'Native parent ID follows same allowlist');
	same(2, $term_validator->validate(array_merge($new, array('parent_slug' => 'parent')), 'category', $parent_rule, $config->blueprint)['parent'], 'Agent parent slug follows same allowlist');
	rejection('taxonomy_term_parent_not_allowed', fn() => $term_validator->validate(array_merge($new, array('parent' => 2)), 'category', array(), $config->blueprint));
	rejection('taxonomy_term_parent_not_allowed', fn() => $term_validator->validate(array_merge($new, array('parent' => 0, 'parent_slug' => 'parent')), 'category', $parent_rule, $config->blueprint));

	$validator = new Page_Validator();
	(new ReflectionProperty(Draft_Service::class, 'validator'))->setValue($draft, $validator);
	$guard = new Structure_Contract_Save_Guard($config, $validator, new Managed_Document_State(), new SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable());
	$GLOBALS['posts'][1]->post_excerpt = $excerpt;
	$GLOBALS['meta'][1] = array(Draft_Service::PAGE_TYPE_META => 'article', Draft_Service::YOAST_METADESC_META => $seo);
	$partial_agent = new ReflectionMethod(Draft_Service::class, 'assert_partial_document');
	$partial_agent->invoke($draft, $GLOBALS['posts'][1], 'article');
	rejection('invalid_seo_metadata', fn() => $partial_agent->invoke($draft, $GLOBALS['posts'][1], 'article', array(Draft_Service::YOAST_METADESC_META => 'short')));
	$validator->calls = 0;
	$request = new WP_REST_Request(array('id' => 1, 'title' => 'Revised title'));
	$prepared = (object) array('post_title' => 'Revised title');
	same($prepared, $guard->validate_save($prepared, $request), 'Title-only native save validates stored body');
	same(1, $validator->calls, 'Partial native body validation is not skipped');
	$GLOBALS['meta'][1][Draft_Service::YOAST_METADESC_META] = '';
	same('smartcloud_agent_invalid_seo_metadata', $guard->validate_save($prepared, $request)->get_error_code(), 'Partial native stored SEO rejection');
	$GLOBALS['meta'][1][Draft_Service::YOAST_METADESC_META] = $seo;
	$GLOBALS['posts'][1]->post_content = 'broken';
	same('smartcloud_agent_validation_failed', $guard->validate_save($prepared, $request)->get_error_code(), 'Partial native invalid body rejection');
	$GLOBALS['posts'][1]->post_content = 'valid';
	$request = new WP_REST_Request(array('id' => 1, 'meta' => array(Draft_Service::YOAST_METADESC_META => 'short')));
	same('smartcloud_agent_invalid_seo_metadata', $guard->validate_save((object) array(), $request)->get_error_code(), 'Metadata-only native new SEO rejection');
	$config->blueprint['published_update_policy'] = 'proposal-only';
	$GLOBALS['posts'][1]->post_status = 'publish';
	same('smartcloud_agent_published_proposal_required', $guard->validate_save((object) array(), new WP_REST_Request(array('id' => 1, 'tags' => array(1))))->get_error_code(), 'Taxonomy-only request cannot bypass proposal gate');

	// Exercise the actual registered early core hook without persisting a test post.
	$GLOBALS['state_writes'] = 0;
	$GLOBALS['is_admin'] = true;
	$GLOBALS['native_can_edit'] = true;
	$_COOKIE[LOGGED_IN_COOKIE] = 'valid-cookie';
	$guard->register_admin();
	$guard->register_admin();
	same(1, count($GLOBALS['hooks']['wp_insert_post_empty_content']), 'Admin registration is idempotent');
	$guard->register();
	$guard->register();
	same(1, count($GLOBALS['hooks']['rest_pre_insert_post']), 'REST registration is idempotent');
	$config->blueprint['native_published_edit_policy'] = 'browser-editor';
	$classic = static function (): void {
		$_POST = array('action' => 'editpost', 'post_ID' => 1, '_wpnonce' => 'nonce:update-post_1');
		$_REQUEST = $_POST;
	};
	$reject_native = static function (string $code, array $postarr) use ($guard): void {
		$before = $GLOBALS['state_writes'];
		try { apply_filters('wp_insert_post_empty_content', false, $postarr); }
		catch (NativeSaveRejected $error) {
			same($code, $error->getMessage(), 'Native early rejection');
			same($before, $GLOBALS['state_writes'], 'Invalid native save never records state');
			same(array('post_content' => 'untouched'), $guard->persist_admin_content(array('post_content' => 'untouched'), $postarr, $postarr, true), 'Invalid save leaves no normalized write queued');
			return;
		}
		throw new RuntimeException('Missing native rejection: ' . $code);
	};
	$classic();
	$postarr = array('ID' => 1, 'post_title' => "Editor's title", 'post_content' => 'stale-body');
	same(false, apply_filters('wp_insert_post_empty_content', false, $postarr), 'Classic partial save accepted');
	same(0, $GLOBALS['state_writes'], 'Early preflight does not record state');
	$data = $guard->persist_admin_content(array('post_content' => 'stale-body'), $postarr, $postarr, true);
	same('refreshed-body', wp_unslash($data['post_content']), 'Exact normalized pattern body reaches core write');
	$guard->record_admin_save(1, $GLOBALS['posts'][1], $GLOBALS['posts'][1]);
	same(1, $GLOBALS['state_writes'], 'Only successful post_updated records state');
	$guard->record_admin_save(1, $GLOBALS['posts'][1], $GLOBALS['posts'][1]);
	same(1, $GLOBALS['state_writes'], 'State is recorded once');
	$reject_native('smartcloud_agent_invalid_title', array('ID' => 1, 'post_title' => str_repeat('x', 201)));
	$reject_native('smartcloud_agent_invalid_excerpt_length', array('ID' => 1, 'post_excerpt' => 'short'));
	$reject_native('smartcloud_agent_invalid_seo_metadata', array('ID' => 1, 'meta_input' => array(Draft_Service::YOAST_METADESC_META => 'short')));
	$reject_native('smartcloud_agent_validation_failed', array('ID' => 1, 'post_content' => 'broken'));
	$_POST['ID'] = 1;
	$_POST['yoast_free_metabox_nonce'] = 'nonce:yoast_free_metabox';
	$_POST['yoast_wpseo_metadesc'] = 'short';
	$reject_native('smartcloud_agent_invalid_seo_metadata', array('ID' => 1));
	$classic();
	$_POST['_wpnonce'] = 'invalid';
	$reject_native('smartcloud_agent_native_auth_required', array('ID' => 1));
	$classic();
	$_COOKIE[LOGGED_IN_COOKIE] = 'invalid-cookie';
	$reject_native('smartcloud_agent_native_auth_required', array('ID' => 1));
	$_COOKIE[LOGGED_IN_COOKIE] = 'valid-cookie';
	$GLOBALS['native_can_edit'] = false;
	$reject_native('smartcloud_agent_native_auth_required', array('ID' => 1));
	$GLOBALS['native_can_edit'] = true;
	$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-value';
	$reject_native('smartcloud_agent_native_auth_required', array('ID' => 1));
	unset($_SERVER['HTTP_AUTHORIZATION']);
	SmartCloud\AgentComposer\Security\ActorIdentity::set(new SmartCloud\AgentComposer\Security\ActorContext('agent:1', '', '', '', array(), '', array(), 'agent', '', 'mcp'));
	$reject_native('smartcloud_agent_native_auth_required', array('ID' => 1));
	SmartCloud\AgentComposer\Security\ActorIdentity::set(null);
	$config->blueprint['native_published_edit_policy'] = 'blocked';
	$reject_native('smartcloud_agent_published_proposal_required', array('ID' => 1));
	$config->blueprint['native_published_edit_policy'] = 'browser-editor';
	$_POST = array('action' => 'inline-save', 'post_ID' => 1, '_inline_edit' => 'nonce:inlineeditnonce');
	$_REQUEST = $_POST;
	$GLOBALS['ajax'] = true;
	same(false, apply_filters('wp_insert_post_empty_content', false, array('ID' => 1, 'post_title' => 'Quick title')), 'Real Quick Edit nonce accepted');
	$reject_native('smartcloud_agent_invalid_excerpt_length', array('ID' => 1, 'post_excerpt' => 'short'));
	$_POST = array();
	$_REQUEST = array('action' => 'edit', 'post' => array(1, 2), '_wpnonce' => 'nonce:bulk-posts');
	$GLOBALS['ajax'] = false;
	same(false, apply_filters('wp_insert_post_empty_content', false, array('ID' => 1)), 'Bulk Edit validates stored complete document');
	$reject_native('smartcloud_agent_invalid_title', array('ID' => 1, 'post_title' => ''));
	$GLOBALS['hooks']['smartcloud_composer_admin_pre_save_validation'][] = static fn(mixed $prepared, array $postarr, int $id): WP_Error => new WP_Error('adapter_rejected', 'Rejected before writes.');
	$reject_native('adapter_rejected', array('ID' => 1));
	unset($GLOBALS['hooks']['smartcloud_composer_admin_pre_save_validation']);
	$_REQUEST['_wpnonce'] = 'invalid';
	$reject_native('smartcloud_agent_native_auth_required', array('ID' => 1));
	$classic();
	foreach (array('revision', 'autosave') as $skip) {
		$GLOBALS[$skip] = true;
		same(false, apply_filters('wp_insert_post_empty_content', false, array('ID' => 1, 'post_title' => '')), 'Revisions/autosaves skip admin guard');
		$GLOBALS[$skip] = false;
	}
	same(false, apply_filters('wp_insert_post_empty_content', false, array('ID' => 99, 'post_title' => '')), 'Ungoverned record skipped');
	$_REQUEST = $_POST = array();
	same(false, apply_filters('wp_insert_post_empty_content', false, array('ID' => 1, 'post_title' => '')), 'Internal draft/proposal operation skipped');
	$GLOBALS['is_admin'] = false;
	$classic();
	same(false, apply_filters('wp_insert_post_empty_content', false, array('ID' => 1, 'post_title' => '')), 'Non-admin operation skipped');

	echo "shared-value-validation: ok\n";
}
