<?php
declare(strict_types=1);
namespace SmartCloud\WPSuite\Hub {
    class ConfigurationReset {
        public static function revision(array $descriptor): string { return hash('sha256', json_encode(array(array_map(fn($key) => $GLOBALS['options'][$key] ?? null, $descriptor['options']), call_user_func($descriptor['revision'])))); }
        public static function deleteOptions(array $keys, bool $transaction = true) {
            if ($transaction) throw new \RuntimeException('Nested transaction is unsafe.');
            foreach ($keys as $key) unset($GLOBALS['options'][$key]);
            return true;
        }
    }
}
namespace SmartCloud\AgentComposer\Infrastructure\Persistence {
    class AuditTable {
        public function record($event, $status, $data, $a=0, $b=0, $c=null, $transaction=true) {
            if ($transaction) throw new \RuntimeException('Audit must join reset transaction.');
            $GLOBALS['audit'][] = $event;
        }
    }
}
namespace {
    define('ARRAY_A', 'ARRAY_A');
    function __($s, $domain = '') { return $s; }
    function current_user_can($cap) { return !empty($GLOBALS['caps'][$cap]); }
    function wp_json_encode($value) { return json_encode($value); }
    function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function add_option($key, $value, ...$rest) { if (isset($GLOBALS['options'][$key])) return false; $GLOBALS['options'][$key]=$value; return true; }
    function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
    function clean_post_cache($id) { $GLOBALS['cleared'][]=$id; }
    function wp_cache_delete($key, $group) {}
    function wp_delete_post($id, $force) {
        if ($id === $GLOBALS['fail_delete']) return false;
        if (!$force || ($GLOBALS['posts'][$id]['post_type'] ?? '') !== 'smartcloud_composer') throw new RuntimeException('Nonconfiguration deletion attempted.');
        unset($GLOBALS['posts'][$id]); return new WP_Post();
    }
    class WP_Post {}
    class WP_Error { public function __construct(public $code, public $message, public $data) {} }
    class Db {
        public string $posts = 'wp_posts'; public string $postmeta = 'wp_postmeta'; public string $last_error = ''; public array $snapshot = array(); public bool $mutate = false;
        public function prepare($query, ...$args) { return $query; }
        public function get_results($query, $format) { return str_contains($query, 'JOIN') ? array() : array_values(array_filter($GLOBALS['posts'], fn($post) => $post['post_type'] === 'smartcloud_composer')); }
        public function get_col($query) {
            if (!str_contains($query, 'FOR UPDATE')) throw new RuntimeException('Missing row lock.');
            if ($this->mutate) $GLOBALS['posts'][1]['post_content']='concurrent';
            return array_column($this->get_results($query, ARRAY_A), 'ID');
        }
        public function query($query) {
            if ($query === 'START TRANSACTION') $this->snapshot=array($GLOBALS['posts'], $GLOBALS['options'], $GLOBALS['audit']);
            if ($query === 'ROLLBACK') [$GLOBALS['posts'], $GLOBALS['options'], $GLOBALS['audit']]=$this->snapshot;
            return 1;
        }
    }
    require dirname(__DIR__) . '/src/Infrastructure/WordPress/Activation.php';
    require dirname(__DIR__) . '/src/Infrastructure/WordPress/EntityPostType.php';
    require dirname(__DIR__) . '/admin/php/configuration-reset.php';
    class Fixture { use \SmartCloud\AgentComposer\Admin\EditingConfigurationReset; }
    $fixture = new Fixture();
    $GLOBALS['wpdb']=new Db(); $GLOBALS['cleared']=array(); $GLOBALS['audit']=array('existing-history'); $GLOBALS['fail_delete']=0;
    $GLOBALS['caps']=array_fill_keys(array('manage_options', \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_EDIT_CONFIG, \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_ACTIVATE_CONFIG), true);
    $GLOBALS['posts']=array(1=>array('ID'=>1,'post_type'=>'smartcloud_composer','post_content'=>'active-config'), 2=>array('ID'=>2,'post_type'=>'smartcloud_composer','post_content'=>'blueprint'), 3=>array('ID'=>3,'post_type'=>'page','post_content'=>'published-content'), 4=>array('ID'=>4,'post_type'=>'smartcloud_proposal','post_content'=>'history'));
    $GLOBALS['options']=array_fill_keys($fixture->editingConfigurationReset()['options'], 'configured');
    $GLOBALS['options']['gatey']='other-plugin'; $GLOBALS['options']['smartcloud_composer_db_schema_version']='preserve';
    $revision=\SmartCloud\WPSuite\Hub\ConfigurationReset::revision($fixture->editingConfigurationReset());
    $checks=0;
    function check($condition, $message) { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }
    $GLOBALS['caps']['manage_options']=false;
    check($fixture->resetEditingConfiguration($revision)->code === 'composer_reset_forbidden', 'Site management permission required.'); $GLOBALS['caps']['manage_options']=true;
    $GLOBALS['caps'][\SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_ACTIVATE_CONFIG]=false;
    check($fixture->resetEditingConfiguration($revision)->code === 'composer_reset_forbidden', 'Native activation permission required.'); $GLOBALS['caps'][\SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_ACTIVATE_CONFIG]=true;
    $GLOBALS['options']['smartcloud_composer_activation_lock']=array('token'=>'external', 'expires'=>time()+30);
    check($fixture->resetEditingConfiguration($revision)->code === 'composer_reset_busy', 'Existing lifecycle operation blocks reset.');
    check($GLOBALS['options']['smartcloud_composer_activation_lock']['token']==='external', 'Foreign lifecycle lock preserved.'); unset($GLOBALS['options']['smartcloud_composer_activation_lock']);
    check($fixture->resetEditingConfiguration('stale')->code === 'composer_reset_conflict', 'Stale confirmations rejected under lock.');
    $GLOBALS['wpdb']->mutate=true;
    check($fixture->resetEditingConfiguration($revision)->code === 'composer_reset_conflict', 'Configuration change during lock acquisition rejected.'); $GLOBALS['wpdb']->mutate=false;
    $GLOBALS['fail_delete']=1;
    check($fixture->resetEditingConfiguration($revision)->code === 'composer_reset_failed', 'Deletion failure is reported.');
    check(isset($GLOBALS['posts'][1], $GLOBALS['posts'][2], $GLOBALS['options']['smartcloud_composer_active_config_set']), 'Transaction restores already deleted configuration and options.');
    check($GLOBALS['audit']===array('existing-history'), 'Failed reset does not append success audit.');
    check(!isset($GLOBALS['options']['smartcloud_composer_activation_lock']), 'Failed reset releases owned lifecycle lock.');
    $GLOBALS['fail_delete']=0;
    check($fixture->resetEditingConfiguration($revision)===true, 'Confirmed configuration reset succeeds.');
    check(!isset($GLOBALS['posts'][1], $GLOBALS['posts'][2]), 'Config sets and blueprints removed.');
    check(isset($GLOBALS['posts'][3], $GLOBALS['posts'][4]), 'Ordinary content and proposals preserved.');
    check(isset($GLOBALS['options']['gatey'], $GLOBALS['options']['smartcloud_composer_db_schema_version']), 'Other plugin and schema metadata preserved.');
    foreach ($fixture->editingConfigurationReset()['options'] as $key) check(!isset($GLOBALS['options'][$key]), 'Every owned configuration option removed.');
    check($GLOBALS['audit']===array('existing-history','configuration-reset'), 'History retained with reset event appended.');
    check(!isset($GLOBALS['options']['smartcloud_composer_activation_lock']), 'Success releases lock.');
    echo "Composer configuration reset: $checks checks passed.\n";
}
