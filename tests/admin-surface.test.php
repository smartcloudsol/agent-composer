<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');

require dirname(__DIR__) . '/src/Infrastructure/WordPress/Activation.php';
require dirname(__DIR__) . '/admin/php/admin.php';
use SmartCloud\AgentComposer\Infrastructure\WordPress\Activation;
$GLOBALS['caps'] = array_fill_keys(array(Activation::CAP_VIEW_STATUS, Activation::CAP_EDIT_CONFIG, Activation::CAP_VALIDATE_CONFIG, Activation::CAP_ACTIVATE_CONFIG), true);
$GLOBALS['sets'] = array('items' => array(), 'active' => '');
$GLOBALS['calls'] = array();
$GLOBALS['preset_available'] = true;
$GLOBALS['discovery_error'] = false;
function __($text, $domain = '') { return $text; }
function current_user_can($cap) { return !empty($GLOBALS['caps'][$cap]); }
function wp_json_encode($value) { return json_encode($value); }
function wp_create_nonce($action) { return 'fixture-rest-nonce'; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error { public function __construct(public $code, public $message = '', public $data = array()) {} }
class WP_REST_Response { public function __construct(private $data, private $status = 200) {} public function get_data() { return $this->data; } public function get_status() { return $this->status; } }
class WP_REST_Request {
    public array $headers = array(); public array $query = array(); public string $body = '';
    public function __construct(public string $method, public string $route) {}
    public function set_header($key, $value) { $this->headers[$key] = $value; }
    public function set_body($value) { $this->body = $value; }
    public function set_query_params($value) { $this->query = $value; }
}
function rest_do_request(WP_REST_Request $request) {
    $route = str_replace('/smartcloud-agent-composer/v1/', '', $request->route);
    $body = json_decode($request->body, true);
    $GLOBALS['calls'][] = array($request->method, $route, $body);
    if ($request->method === 'POST' && ($request->headers['x_wp_nonce'] ?? '') !== 'fixture-rest-nonce') throw new RuntimeException('Canonical mutation nonce missing.');
    if ($route === 'status') return new WP_REST_Response(array('active_config_set' => $GLOBALS['sets']['active']));
    if ($route === 'config-sets') return new WP_REST_Response($GLOBALS['sets']);
    if ($route === 'content-proposals') {
        if ($request->query !== array('states' => 'ready-for-review', 'per_page' => 5)) throw new RuntimeException('Review count must query only ready proposals.');
        return new WP_REST_Response(array('total' => 3));
    }
    if ($route === 'presets') return new WP_REST_Response(array('items' => array(array('id' => 'universal-gutenberg', 'label' => 'Universal Gutenberg', 'available' => $GLOBALS['preset_available']), array('id' => 'detected-theme-starter', 'label' => 'Unavailable', 'available' => false))));
    if ($route === 'presets/universal-gutenberg/instantiate') {
        if ($request->method !== 'POST' || $body !== array('label' => '')) throw new RuntimeException('Preset must use canonical default label with no config overrides.');
        array_unshift($GLOBALS['sets']['items'], array('config_set' => 'draft-0', 'label' => 'Universal Gutenberg', 'active' => false));
        return new WP_REST_Response(array('active' => false), 201);
    }
    if ($route === 'discovery' && $request->method === 'POST') return $GLOBALS['discovery_error'] ? new WP_REST_Response(array('message' => 'Discovery unavailable'), 503) : new WP_REST_Response(array('snapshot' => 'synthetic-observations'), 201);
    throw new RuntimeException('Unexpected canonical route: ' . $route);
}
$checks = 0;
function check($value, $message) { global $checks; ++$checks; if (!$value) throw new RuntimeException($message); }
function mutations(): array { return array_values(array_filter($GLOBALS['calls'], static fn($call) => $call[0] === 'POST')); }
$admin = new \SmartCloud\AgentComposer\Admin\AdminPage();
$surface = $admin->editingSurface();
check(!$surface['configured'] && $surface['status']['label'] === 'Choose a preset', 'Fresh install offers preset choice.');
check($surface['steps'] === array() && $surface['values'] === array(), 'Simple screen has no naming or configuration wizard.');
check(array_column($surface['actions'], 'id') === array('start-preset'), 'Fresh configuration exposes only the useful preset action.');
check(count($surface['actions'][0]['fields'][0]['options']) === 1, 'Unavailable presets excluded.');
check(!isset($surface['primary_link']), 'No edit-primary before configuration exists.');
$helpIds = array_column($surface['help'], 'id');
check(in_array('preset-start', $helpIds, true) && in_array('site-rescan', $helpIds, true), 'Preset and rescan help replaces working-copy wizard help.');
check(in_array($surface['actions'][0]['fields'][0]['help_id'], $helpIds, true), 'Preset field resolves contextual help.');
check(mutations() === array(), 'Status reads never instantiate, clone, activate or rescan.');
$registry = $admin->registerHubCapabilities(array());
check(!isset($registry[0]['surface_save']), 'Simplified PATCH callback is not registered.');
check($admin->saveEditingSurface(array('label' => 'Legacy rename'), $surface['revision'])->data['status'] === 403, 'Legacy direct save cannot clone or rename.');
foreach (array('clone', 'validate', 'activate') as $action) check($admin->editingSurfaceAction($action, array('config_set' => 'draft-0'))->data['status'] === 403, 'Retired simple mutation is rejected.');
foreach (array(array('preset' => 'missing'), array('preset' => 'detected-theme-starter'), array('preset' => '../unsafe'), array('preset' => 'universal-gutenberg', 'label' => 'Forbidden rename')) as $input) check($admin->editingSurfaceAction('start-preset', $input)->data['status'] === 400, 'Preset action only accepts a currently available catalog preset.');
$GLOBALS['preset_available'] = false;
check(!in_array('start-preset', array_column($admin->editingSurface()['actions'], 'id'), true), 'No unavailable-only preset selector.');
check($admin->editingSurfaceAction('start-preset', array('preset' => 'universal-gutenberg'))->data['status'] === 400, 'Preset availability is checked again at mutation time.');
$GLOBALS['preset_available'] = true;
$created = $admin->editingSurfaceAction('start-preset', array('preset' => 'universal-gutenberg'));
check(!is_wp_error($created) && str_ends_with($created['redirect'], '&section=configuration'), 'Creation continues in the existing Advanced configuration screen.');
check($GLOBALS['sets']['active'] === '' && count($GLOBALS['sets']['items']) === 1, 'Preset creates one inactive configuration without activation.');
check(count(mutations()) === 1 && mutations()[0][1] === 'presets/universal-gutenberg/instantiate', 'Creation uses only the canonical preset endpoint.');
$draftSurface = $admin->editingSurface();
check($draftSurface['configured'] && $draftSurface['status']['label'] === 'Configuration available', 'Any existing draft is recognized as a configuration.');
check(array_column($draftSurface['actions'], 'id') === array() && $draftSurface['primary_link']['label'] === 'Edit in Advanced', 'Existing draft hides preset and offers Advanced editing.');
$before = $GLOBALS['sets'];
check($admin->editingSurfaceAction('start-preset', array('preset' => 'universal-gutenberg'))->data['status'] === 409, 'Stale fresh-site action rejects a concurrently created draft.');
check($GLOBALS['sets'] === $before && count(mutations()) === 1, 'Rejected duplicate leaves configuration unchanged.');
check(!is_wp_error($admin->editingSurfaceAction('rescan-site', array())), 'Rescan reuses canonical discovery endpoint.');
check(mutations()[1][1] === 'discovery' && $GLOBALS['sets'] === $before, 'Rescan changes observations only, not configuration.');
check($admin->editingSurfaceAction('rescan-site', array('config_set' => 'draft-0'))->data['status'] === 400, 'Rescan rejects configuration input.');
$GLOBALS['discovery_error'] = true;
check($admin->editingSurfaceAction('rescan-site', array())->data['status'] === 503, 'Canonical discovery errors propagate.');
$GLOBALS['discovery_error'] = false;
$GLOBALS['sets']['active'] = 'draft-0';
$active = $admin->editingSurface();
check($active['status']['label'] === 'Configuration active' && array_column($active['actions'], 'id') === array(), 'Active configuration never exposes starter or mutation wizard.');
foreach ($active['metrics'] as $metric) check(in_array($metric['help_id'], $helpIds, true), 'Each metric resolves its help topic.');
$GLOBALS['caps'][Activation::CAP_MERGE_PROPOSALS] = true;
check(in_array(array('label' => 'Awaiting human review', 'help_id' => 'proposal-review', 'value' => 3), $admin->editingSurface()['metrics'], true), 'Reviewers see only ready-for-review proposals.');
$GLOBALS['caps'][Activation::CAP_EDIT_CONFIG] = false;
$validator = $admin->editingSurface();
check($validator['can_edit'] && $validator['actions'] === array(), 'Validation-only user has no summary mutation actions.');
check(!is_wp_error($admin->editingSurfaceAction('rescan-site', array())), 'Rescan does not require config-edit permission.');
check($validator['primary_link']['label'] === 'View in Advanced' && str_ends_with($validator['primary_link']['url'], 'page=smartcloud-agent-composer'), 'Read-only active configuration links to Advanced viewing.');
check($admin->editingSurfaceAction('start-preset', array('preset' => 'universal-gutenberg'))->data['status'] === 403, 'Preset creation requires config-edit permission.');
$GLOBALS['caps'][Activation::CAP_VALIDATE_CONFIG] = false;
$readOnly = $admin->editingSurface();
check(!$readOnly['can_edit'] && $readOnly['actions'] === array(), 'Status-only user receives no mutation actions.');
check($admin->editingSurfaceAction('rescan-site', array())->data['status'] === 403, 'Rescan requires validate permission.');
$GLOBALS['caps'][Activation::CAP_VIEW_STATUS] = false;
check($admin->editingSurface()->data['status'] === 403, 'Status read requires its permission.');
check($admin->editingSurfaceAction('rescan-site', array())->data['status'] === 403, 'Rescan also requires view permission.');
echo "Composer surface: {$checks} checks passed.\n";
