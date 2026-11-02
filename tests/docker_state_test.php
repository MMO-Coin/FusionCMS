<?php

declare(strict_types=1);
require dirname(__DIR__) . '/docker/state.php';

$root = sys_get_temp_dir() . '/fusioncms-docker-state-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$site = $root . '/site';
$defaults = $root . '/defaults';
$state = $root . '/state';
$checks = 0;
function checkDockerState(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function putDockerFixture(string $path, string $content): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    file_put_contents($path, $content);
}
function removeDockerFixture(string $directory): void
{
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) removeDockerFixture($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($directory);
}
try {
    $config = 'application/config/config.php';
    $database = 'application/config/Database.php';
    $module = 'application/modules/example/config/settings.php';
    $manifest = 'application/modules/example/manifest.json';
    $theme = 'application/themes/example/config/settings.php';
    foreach ([$config, $database, $module, $manifest, $theme] as $file) putDockerFixture($defaults . '/' . $file, 'original');
    fusionDockerPrepare($site, $defaults, $state);
    checkDockerState(file_get_contents($site . '/' . $config) === 'original', 'Fresh root configuration was not seeded');
    checkDockerState(file_get_contents($site . '/' . $database) === 'original', 'Database defaults were not seeded');
    foreach ([$module, $manifest, $theme] as $file) checkDockerState(file_get_contents($state . '/' . $file) === 'original', 'Settings were not seeded: ' . $file);

    putDockerFixture($site . '/' . $database, 'installed database credentials');
    putDockerFixture($site . '/application/config/fusion.php', 'installed site settings');
    putDockerFixture($site . '/application/config/owner.php', 'installed owner');
    putDockerFixture($site . '/writable/install/.lock', 'installed');
    putDockerFixture($site . '/writable/uploads/example.txt', 'uploaded content');
    foreach ([$module, $manifest, $theme] as $file) putDockerFixture($state . '/' . $file, 'administrator changes');
    fusionDockerPrepare($site, $defaults, $state);
    checkDockerState(file_get_contents($site . '/' . $database) === 'installed database credentials', 'Restart replaced database credentials');
    foreach ([$module, $manifest, $theme] as $file) checkDockerState(file_get_contents($state . '/' . $file) === 'administrator changes', 'Restart replaced edited settings');

    // A new image refreshes untouched defaults while preserving local edits.
    foreach ([$config, $database] as $file) putDockerFixture($defaults . '/' . $file, 'new image defaults');
    putDockerFixture($defaults . '/application/config/NewConfig.php', 'new configuration file');
    fusionDockerPrepare($site, $defaults, $state);
    checkDockerState(file_get_contents($site . '/' . $config) === 'new image defaults', 'Untouched defaults were not updated');
    checkDockerState(file_get_contents($site . '/' . $database) === 'installed database credentials', 'Image upgrade replaced credentials');
    checkDockerState(file_exists($site . '/application/config/NewConfig.php'), 'New default files were not added');
    // A second upgrade must retain the original baseline for modified files.
    putDockerFixture($defaults . '/' . $database, 'third image defaults');
    fusionDockerPrepare($site, $defaults, $state);
    checkDockerState(file_get_contents($site . '/' . $database) === 'installed database credentials', 'Second upgrade replaced credentials');
    foreach (['application/config/fusion.php', 'application/config/owner.php', 'writable/install/.lock', 'writable/uploads/example.txt'] as $file) checkDockerState(file_exists($site . '/' . $file), 'Installed file was lost: ' . $file);

    if (PHP_OS_FAMILY !== 'Windows') {
        $image = $root . '/image';
        $snapshot = $root . '/snapshot';
        $volume = $root . '/volume';
        foreach ([$config, $module, $manifest, $theme] as $file) putDockerFixture($image . '/' . $file, 'image defaults');
        fusionDockerSnapshot($image, $snapshot, $volume);
        checkDockerState(is_link($image . '/application/modules/example/config'), 'Module config is not linked to persistent storage');
        checkDockerState(is_link($image . '/' . $manifest), 'Module activation is not linked to persistent storage');
        checkDockerState(is_link($image . '/application/themes/example/config'), 'Theme config is not linked to persistent storage');
        fusionDockerPrepare($image, $snapshot, $volume);
        putDockerFixture($image . '/' . $module, 'saved through application path');
        checkDockerState(file_get_contents($volume . '/' . $module) === 'saved through application path', 'Application writes bypassed persistent settings');
    } else {
        echo "Linux symlink checks run in Docker CI.\n";
    }
    echo $checks . " Docker state checks passed.\n";
} finally {
    if (!str_starts_with($root, sys_get_temp_dir() . '/fusioncms-docker-state-')) throw new RuntimeException('Unexpected fixture root');
    removeDockerFixture($root);
}
