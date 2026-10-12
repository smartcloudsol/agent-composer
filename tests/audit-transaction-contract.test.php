<?php
declare(strict_types=1);

use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Execution\Audit_Logger;
use SmartCloud\AgentComposer\Execution\Abilities;
use SmartCloud\AgentComposer\Execution\Execution_Exception;
use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;

function esc_sql($value) { return $value; }
function sanitize_text_field($value) { return $value; }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $value)); }
function wp_unslash($value) { return $value; }
function wp_generate_uuid4() { return '11111111-1111-4111-8111-111111111111'; }
function get_current_user_id() { return 7; }
function current_user_can($cap) { return true; }
function is_user_logged_in() { return true; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function absint($value) { return abs((int) $value); }
function do_action($name, ...$args) { $GLOBALS['actions'][] = $name; }
class WP_Error { public function __construct(public $code, public $message, public $data = []) {} }

require dirname(__DIR__) . '/src/Security/ActorIdentity.php';
require dirname(__DIR__) . '/src/Domain/Configuration/CanonicalJson.php';
require dirname(__DIR__) . '/src/Infrastructure/Persistence/AuditTable.php';
require dirname(__DIR__) . '/src/Infrastructure/WordPress/Activation.php';
require dirname(__DIR__) . '/src/Execution/Audit_Logger.php';
require dirname(__DIR__) . '/src/Execution/Abilities.php';
require dirname(__DIR__) . '/src/Execution/Execution_Exception.php';

// Deterministic SQL double models the transaction boundary and audit lock order.
// It is not a substitute for the two-connection InnoDB acceptance test.
final class AuditDb {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $last_error = '';
    public array $queries = [];
    public array $inserts = [];
    public array $events = [];
    public array $failures = [];
    public bool $mutexPresent = true;
    public bool $locked = false;
    public bool $rollbackFails = false;
    public bool $advanceAfterRollback = false;
    public string $tail = 'original-tail';
    private array $snapshot = [];
    public function prepare($sql, ...$values): string {
        foreach ($values as $value) $sql = preg_replace('/%s/', "'" . $value . "'", $sql, 1);
        return $sql;
    }
    private function fails(string $stage): bool {
        $this->last_error = '';
        if (empty($this->failures[$stage])) return false;
        $this->last_error = array_shift($this->failures[$stage]);
        return true;
    }
    public function query(string $sql): int|false {
        $this->queries[] = $sql;
        if ($this->fails($sql)) return false;
        if ($sql === 'START TRANSACTION') {
            $this->snapshot = [$this->events, $this->tail];
            $this->locked = false;
        }
        if ($sql === 'ROLLBACK') {
            if ($this->rollbackFails) return false;
            [$this->events, $this->tail] = $this->snapshot;
            $this->locked = false;
            if ($this->advanceAfterRollback) $this->tail = 'concurrent-committed-tail';
        }
        if ($sql === 'COMMIT') $this->locked = false;
        return 1;
    }
    public function get_var(string $sql): ?string {
        $this->queries[] = $sql;
        if (str_contains($sql, 'option_id')) {
            if ($this->fails('mutex')) return null;
            $this->locked = $this->mutexPresent;
            return $this->mutexPresent ? '42' : null;
        }
        if (!$this->locked) throw new RuntimeException('Audit index read before permanent mutex row lock.');
        if (str_contains($sql, 'SELECT event_uuid')) {
            if ($this->fails('uuid')) return null;
            preg_match("/event_uuid = '([^']+)'/", $sql, $matches);
            return isset($this->events[$matches[1]]) ? $matches[1] : null;
        }
        if ($this->fails('tail')) return null;
        return $this->tail === '' ? null : $this->tail;
    }
    public function insert(string $table, array $data, array $formats): int|false {
        if (!$this->locked) throw new RuntimeException('Append without mutex.');
        $this->inserts[] = $data;
        if ($this->fails('insert')) return false;
        $this->events[$data['event_uuid']] = $data;
        $this->tail = $data['event_hash'];
        return 1;
    }
}

$checks = 0;
function check(bool $value, string $message): void {
    global $checks; ++$checks;
    if (!$value) throw new RuntimeException($message);
}
function expect_failure(callable $operation, int $code = 0): Throwable {
    try { $operation(); } catch (Throwable $error) {
        check($error->getCode() === $code, 'Expected native retry classification.'); return $error;
    }
    throw new RuntimeException('Expected failure.');
}
function db(): AuditDb { return $GLOBALS['wpdb'] = new AuditDb(); }
function starts(AuditDb $db): int { return count(array_filter($db->queries, fn($query) => $query === 'START TRANSACTION')); }
$audit = new AuditTable();
$uuid = '22222222-2222-4222-8222-222222222222';

$db = db();
check($audit->record('read', 'success', ['token' => 'private'], 0, 0, $uuid) === $uuid, 'Append returns stable identity.');
check(str_contains($db->queries[1], 'option_id') && str_contains($db->queries[2], 'event_uuid'), 'Mutex is first locking read.');
check(json_decode($db->inserts[0]['context_json'], true)['token'] === '[redacted]', 'Retry refactor preserves redaction.');
$firstHash = $db->tail;
$audit->record('read', 'success', [], 0, 0, '33333333-3333-4333-8333-333333333333');
check($db->inserts[1]['previous_hash'] === $firstHash, 'Sequential append uses committed predecessor.');
$payload = $db->inserts[1];
$payload['context'] = json_decode($payload['context_json'], true);
unset($payload['context_json'], $payload['event_hash']);
check(hash('sha256', CanonicalJson::encode($payload)) === $db->inserts[1]['event_hash'], 'Stored hash verifies against the existing canonical chain contract.');
$count = count($db->inserts);
$audit->record('read', 'success', [], 0, 0, $uuid);
check(count($db->inserts) === $count, 'Duplicate UUID does not append again.');

foreach (['Deadlock found when trying to get lock; try restarting transaction' => 1213, 'Lock wait timeout exceeded; try restarting transaction' => 1205] as $message => $code) {
    $db = db(); $db->failures['insert'] = [$message]; $db->advanceAfterRollback = true;
    $audit->record('read', 'success', [], 0, 0, $uuid);
    check(starts($db) === 2 && count($db->events) === 1, 'Owned transient failure retries whole transaction once.');
    check($db->inserts[0]['event_uuid'] === $db->inserts[1]['event_uuid'], 'Retry preserves event UUID.');
    check($db->inserts[1]['previous_hash'] === 'concurrent-committed-tail' && $db->inserts[0]['event_hash'] !== $db->inserts[1]['event_hash'], 'Retry rereads predecessor and recomputes hash.');
    check(array_search('ROLLBACK', $db->queries, true) < array_search('START TRANSACTION', array_slice($db->queries, 1), true) + 1, 'Rollback precedes retry start.');
    $db = db(); $db->failures['insert'] = [$message];
    expect_failure(fn() => $audit->record('read', 'success', [], 0, 0, $uuid, false), $code);
    check(starts($db) === 0 && !in_array('ROLLBACK', $db->queries, true) && !in_array('COMMIT', $db->queries, true), 'Caller-owned failure propagates without transaction control or retry.');
}

$db = db(); $db->failures['insert'] = array_fill(0, 4, 'Deadlock found when trying to get lock');
expect_failure(fn() => $audit->record('read', 'success'), 1213);
check(starts($db) === 3 && count($db->inserts) === 3, 'Retries are bounded to three attempts.');
$db = db(); $db->failures['mutex'] = ['Lock wait timeout exceeded']; $db->advanceAfterRollback = true;
$audit->record('read', 'success');
check(starts($db) === 2 && count($db->inserts) === 1, 'Waiting on another appender retries before any audit-index lookup.');
check($db->inserts[0]['previous_hash'] === 'concurrent-committed-tail', 'Appender waiting for the mutex reads the newly committed chain head.');
foreach (['START TRANSACTION', 'mutex', 'uuid', 'tail', 'insert', 'COMMIT'] as $stage) {
    $db = db(); $db->failures[$stage] = ['Non-transient database failure'];
    expect_failure(fn() => $audit->record('read', 'success'));
    check(starts($db) === 1, 'Non-transient query failure never retries.');
    if ($stage !== 'COMMIT') check(count($db->events) === 0, 'Failed query never commits an event or fabricated predecessor.');
}
$db = db(); $db->mutexPresent = false;
expect_failure(fn() => $audit->record('read', 'success'));
check(count($db->inserts) === 0, 'Missing permanent mutex fails closed.');
$db = db(); $db->tail = '';
$audit->record('read', 'success');
check($db->inserts[0]['previous_hash'] === '', 'Empty chain can append under permanent lock.');
$db = db(); $db->failures['COMMIT'] = ['Deadlock found when trying to get lock'];
expect_failure(fn() => $audit->record('read', 'success'));
check(starts($db) === 1, 'Ambiguous failed commit is not replayed even with transient message.');
$db = db(); $db->failures['insert'] = ['Deadlock found when trying to get lock']; $db->rollbackFails = true;
expect_failure(fn() => $audit->record('read', 'success'), 1213);
check(starts($db) === 1, 'Failed rollback prevents retry.');
$db = db();
$audit->record('write', 'success', [], 0, 0, $uuid, false);
check($db->locked && starts($db) === 0 && !in_array('COMMIT', $db->queries, true), 'Successful caller-owned append retains mutex until caller commit.');
$db = db(); $db->prefix = 'wp_2_'; $db->options = 'wp_2_options';
$audit->record('read', 'success');
check(str_contains($db->queries[1], 'wp_2_options'), 'Multisite locks the current site option row.');

$logger = new Audit_Logger($audit);
$reflection = new ReflectionClass(Abilities::class);
$abilities = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('audit')->setValue($abilities, $logger);
$reflection->getProperty('access_guard')->setValue($abilities, null);
$execute = $reflection->getMethod('execute');
$db = db(); $db->mutexPresent = false; $GLOBALS['actions'] = []; $calls = 0;
$result = $execute->invoke($abilities, 'read', [], function() use (&$calls) { ++$calls; return ['post_id' => 9]; });
check($result instanceof WP_Error && $result->code === 'smartcloud_agent_internal_error', 'Mandatory success audit failure remains a controlled500.');
check($calls === 1, 'Audit failure never reexecutes the content callback.');
check(in_array('smartcloud_composer_audit_error', $GLOBALS['actions'], true), 'Secondary failed audit emits safe diagnostic hook.');
$result = $execute->invoke($abilities, 'read', [], fn() => throw new Execution_Exception('blueprint_not_found', 'Missing blueprint.'));
check($result instanceof WP_Error && $result->code === 'smartcloud_agent_blueprint_not_found', 'Secondary audit failure preserves original domain error.');

$raw_message = '<img src=x onerror=alert(1)> & "diagnostic"';
$raw_details = ['meta_key' => '<raw>&identifier', 'page_types' => ['service', 'examination']];
$result = $execute->invoke($abilities, 'read', [], fn() => throw new Execution_Exception('raw_contract_diagnostic', $raw_message, 0, $raw_details));
check($result instanceof WP_Error && $result->message === $raw_message, 'Execution diagnostics must retain raw machine messages in WP_Error, not HTML entities.');
$decoded = json_decode(json_encode(['message' => $result->message, 'data' => $result->data], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), true);
check($decoded['message'] === $raw_message && $decoded['data']['meta_key'] === $raw_details['meta_key'] && $decoded['data']['page_types'] === $raw_details['page_types'], 'JSON encoding preserves diagnostic values and structured details without an HTML sink.');

echo "Composer audit transaction contract: {$checks} checks passed.\n";
