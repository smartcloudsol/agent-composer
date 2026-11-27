<?php
declare(strict_types=1);
$options = []; $allowed = true;
class WP_Error { public function __construct(public string $code = '', public string $message = '', public mixed $data = null) {} }
function __(string $text, string $domain = ''): string { return $text; }
function current_user_can(string $cap): bool { return $GLOBALS['allowed']; }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['options'][$key] ?? $default; }
function update_option(string $key, mixed $value, bool $autoload = false): bool { $GLOBALS['options'][$key] = $value; return true; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $value)); }
function wp_parse_url(string $url): mixed { return parse_url($url); }
function apply_filters(string $name, mixed $value, mixed ...$args): mixed { return $value; }
function do_action(string $name, mixed ...$args): void {}
function check(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
require __DIR__ . '/../src/Security/McpSecuritySettings.php';
require __DIR__ . '/../src/Security/DeploymentCandidate.php';
use SmartCloud\AgentComposer\Security\McpSecuritySettings;
use SmartCloud\AgentComposer\Security\DeploymentCandidate;
$settings = new McpSecuritySettings();
$base = ['identity_provider' => ['prefer_manual' => true, 'manual' => ['region' => 'eu-central-1', 'user_pool_id' => 'eu-central-1_Pool']], 'group_roles' => ['editors' => 'publisher'], 'clients' => [['label' => 'Existing', 'client_id' => 'existing', 'role_ceiling' => 'publisher', 'scopes' => ['composer.read', 'composer.draft']]], 'unknown_client_policy' => 'human-role', 'enforce_scopes' => true];
$settings->save($base);
$fields = ['clientId' => 'newclient', 'resourceUri' => 'https://example.test/mcp', 'region' => 'eu-central-1', 'userPoolId' => 'eu-central-1_Pool'];
$allowed = false;
check(DeploymentCandidate::sections($fields) === [] && DeploymentCandidate::apply('access', $fields) instanceof WP_Error, 'Permission required for read and apply.');
$allowed = true;
$sections = DeploymentCandidate::sections($fields);
check(($sections['access']['defaultSelected'] ?? true) === false && !isset($sections['access']['blocked']), 'Reader client requires explicit selection.');
check(DeploymentCandidate::apply('access', $fields) === true, 'Matching pool and empty URI can be applied.');
$after = $settings->get();
check($after['clients'][1]['role_ceiling'] === 'reader' && $after['clients'][1]['scopes'] === ['composer.read'], 'New client is capped at Reader with canonical read scope.');
check($after['clients'][0]['role_ceiling'] === 'publisher' && $after['group_roles'] === ['editors' => 'publisher'] && $after['unknown_client_policy'] === 'human-role' && $after['enforce_scopes'], 'Existing client, group, scope enforcement and unknown-client policy preserved.');
check($after['oauth_resource_uri'] === $fields['resourceUri'] && DeploymentCandidate::sections($fields) === [], 'Applied candidate converges.');
$before = $after;
foreach ([['region' => 'us-east-1'], ['userPoolId' => 'eu-central-1_Other'], ['resourceUri' => 'https://other.test/mcp'], ['resourceUri' => 'http://example.test'], ['clientId' => 'BAD!']] as $override) {
    $bad = array_merge($fields, $override, ['clientId' => $override['clientId'] ?? 'another']);
    check(isset(DeploymentCandidate::sections($bad)['access']['blocked']) && DeploymentCandidate::apply('access', $bad) instanceof WP_Error, 'Invalid candidate or conflict must be blocked.');
    check($settings->get() === $before, 'Blocked candidate cannot mutate policy.');
}
$existing = $fields; $existing['clientId'] = 'existing';
check(DeploymentCandidate::sections($existing) === [], 'Existing allowed client and matching resource need no change.');
echo "composer deployment candidate: ok\n";
