<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . '/' . $relative);
    if (!is_string($content)) {
        throw new RuntimeException('Could not read ' . $relative);
    }
    return $content;
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$drafts = $read('src/Execution/Draft_Service.php');
$assert(!str_contains($drafts, 'remove_filter( $tag, \'wp_filter_post_kses\''), 'Draft writes must never remove WordPress KSES filters.');
$assert(!str_contains($drafts, 'update_post_preserving_validated_html'), 'The KSES-bypassing draft helper must remain removed.');

$abilities = $read('src/Execution/Abilities.php');
$assert(!preg_match('/__\(\s*\$(?:label|description)\s*,/', $abilities), 'Ability metadata must not pass variables to gettext.');
$assert(!str_contains($abilities, "'core_html_javascript'"), 'Runtime capabilities must not advertise Custom HTML JavaScript.');

$controller = $read('src/Infrastructure/WordPress/ConfigurationController.php');
$assert(!preg_match("/'\/(?:config-sets|discovery|presets)[^']*'[\\s\\S]{0,300}CAP_VIEW_STATUS/", $controller), 'Administrative configuration reads must not use the agent status capability.');

$catalog = $read('src/Execution/Block_Catalog.php');
$assert(str_contains($catalog, "if ( 'core/html' === \$name ) {\n\t\t\treturn false;"), 'The block catalog must reject core/html unconditionally.');

$preset_files = array_merge(
    glob($root . '/presets/wpsuite/blueprints/*.json') ?: array(),
    array(
        $root . '/presets/wpsuite/site-contract.json',
        $root . '/presets/wpsuite/wpsuite-site-contract.package.json',
    )
);
foreach ($preset_files as $file) {
    $json = file_get_contents($file);
    $assert(is_string($json) && json_decode($json, true) !== null, basename($file) . ' must remain valid JSON.');
    $assert(!str_contains((string) $json, '"core/html"'), basename($file) . ' must not opt in to core/html.');
    $assert(!str_contains((string) $json, '"custom_html": true'), basename($file) . ' must not enable Custom HTML.');
    $assert(!str_contains((string) $json, '"core_html_javascript": true'), basename($file) . ' must not enable arbitrary JavaScript.');
}

echo "wporg-review-contract: ok\n";
