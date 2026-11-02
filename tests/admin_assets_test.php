<?php

if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../application/libraries/AdminAssets.php';
function checkAssets(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
function pageAssets(string $path): array { return AdminAssets::forContent(file_get_contents(__DIR__ . '/../application/' . $path)); }
$pages = [
    ['modules/admin/views/emaillogs.tpl', []],
    ['modules/admin/views/logging/logging.tpl', []],
    ['modules/admin/views/dashboard.tpl', ['charts', 'multiselect']],
    ['modules/admin/views/accounts/accounts_search.tpl', ['datatables']],
    ['modules/admin/views/email_template/edit.tpl', ['codemirror']],
    ['modules/admin/views/modules.tpl', ['dropzone']],
    ['modules/news/views/admin_new.tpl', ['imagesloader']],
    ['modules/admin/views/theme.tpl', ['carousel']],
    ['modules/admin/views/settings.tpl', ['spinner']],
    ['modules/store/views/admin_add_group.tpl', ['spinner', 'iconpicker']],
];
foreach ($pages as [$path, $expected]) {
    $assets = pageAssets($path);
    $groups = $assets['groups']; sort($groups); sort($expected);
    checkAssets($groups === $expected, $path . ' requests only the plugins its controls use');
    foreach (['css', 'js'] as $type) foreach ($assets[$type] as $asset) checkAssets(is_file(__DIR__ . '/../' . $asset), 'Asset exists: ' . $asset);
}
$all = ['datatables', 'charts', 'codemirror', 'imagesloader', 'dropzone', 'carousel', 'datepicker', 'select2', 'multiselect', 'spinner', 'popup', 'jqueryui', 'sparkline', 'flot', 'morris', 'gauge', 'liquidmeter', 'iconpicker', 'appear', 'switchery'];
$assets = AdminAssets::forContent('', $all);
foreach (['css', 'js'] as $type) {
    checkAssets(count($assets[$type]) === count(array_unique($assets[$type])), 'Explicit asset groups have no duplicate ' . $type . ' paths');
    foreach ($assets[$type] as $asset) if (!str_starts_with($asset, 'https://')) checkAssets(is_file(__DIR__ . '/../' . $asset), 'Declared dependency exists: ' . $asset);
}
$scripts = implode("\n", $assets['js']);
checkAssets(strpos($scripts, 'jquery-ui.js') < strpos($scripts, 'bootstrap-datepicker.js'), 'jQuery UI loads before the Bootstrap datepicker namespace is selected');
checkAssets(strpos($scripts, 'lib/codemirror.js') < strpos($scripts, 'mode/htmlmixed/htmlmixed.js') && strpos($scripts, 'mode/css/css.js') < strpos($scripts, 'mode/htmlmixed/htmlmixed.js'), 'CodeMirror core and embedded modes load before HTML mode');
checkAssets(strpos($scripts, 'raphael/raphael.js') < strpos($scripts, 'morris/morris.js'), 'Morris receives Raphael first');
try { AdminAssets::forContent('', ['unknown']); throw new RuntimeException('Expected a declaration error'); }
catch (InvalidArgumentException $expected) { echo "PASS: Unknown explicit groups report a declaration error\n"; }
