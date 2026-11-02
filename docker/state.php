<?php

// Docker-only storage setup. Normal installations never load this file.
declare(strict_types=1);

function fusionDockerFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
        }
    }
    sort($files);
    return $files;
}

function fusionDockerCopy(string $source, string $target): void
{
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
        throw new RuntimeException('Cannot create ' . dirname($target));
    }
    if (!copy($source, $target)) {
        throw new RuntimeException('Cannot copy ' . $source . ' to ' . $target);
    }
}

function fusionDockerSnapshot(string $site, string $defaults, string $state): void
{
    foreach (fusionDockerFiles($site . '/application/config') as $relative) {
        fusionDockerCopy($site . '/application/config/' . $relative, $defaults . '/application/config/' . $relative);
    }
    foreach (['modules', 'themes'] as $kind) {
        foreach (glob($site . '/application/' . $kind . '/*', GLOB_ONLYDIR) as $directory) {
            $prefix = 'application/' . $kind . '/' . basename($directory);
            if (is_dir($directory . '/config')) {
                foreach (fusionDockerFiles($directory . '/config') as $relative) {
                    $file = $prefix . '/config/' . $relative;
                    fusionDockerCopy($site . '/' . $file, $defaults . '/' . $file);
                    unlink($site . '/' . $file);
                }
                // All config trees in the image contain files, but may have nested directories.
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory . '/config', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($iterator as $entry) {
                    if ($entry->isDir()) rmdir($entry->getPathname());
                }
                rmdir($directory . '/config');
                if (!symlink($state . '/' . $prefix . '/config', $directory . '/config')) {
                    throw new RuntimeException('Cannot link ' . $directory . '/config');
                }
            }
            // Module activation is stored in manifest.json by the admin panel.
            if ($kind === 'modules' && is_file($directory . '/manifest.json')) {
                $file = $prefix . '/manifest.json';
                fusionDockerCopy($site . '/' . $file, $defaults . '/' . $file);
                unlink($site . '/' . $file);
                if (!symlink($state . '/' . $file, $site . '/' . $file)) {
                    throw new RuntimeException('Cannot link ' . $file);
                }
            }
        }
    }
}

function fusionDockerPrepare(string $site, string $defaults, string $state): void
{
    if (!is_dir($state) && !mkdir($state, 0755, true)) {
        throw new RuntimeException('Cannot create Docker state directory');
    }
    $manifest = $state . '/.defaults.json';
    $previous = is_file($manifest) ? json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR) : [];
    $next = [];
    foreach (fusionDockerFiles($defaults) as $relative) {
        $source = $defaults . '/' . $relative;
        $target = (str_starts_with($relative, 'application/config/') ? $site : $state) . '/' . $relative;
        $hash = hash_file('sha256', $source);
        if (!file_exists($target) || (isset($previous[$relative]) && hash_file('sha256', $target) === $previous[$relative])) {
            fusionDockerCopy($source, $target);
        } elseif (isset($previous[$relative]) && $previous[$relative] !== $hash) {
            // Keep administrator changes. Never replace them with new image defaults.
            fwrite(STDERR, 'Review changed image defaults for ' . $relative . "\n");
        }
        // Keep the original baseline for edited files, including across multiple upgrades.
        $next[$relative] = hash_file('sha256', $target) === $hash ? $hash : ($previous[$relative] ?? $hash);
    }
    if (file_put_contents($manifest . '.tmp', json_encode($next, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false || !rename($manifest . '.tmp', $manifest)) {
        throw new RuntimeException('Cannot save Docker defaults manifest');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli' || $argc !== 5 || !in_array($argv[1], ['snapshot', 'prepare'], true)) {
        throw new InvalidArgumentException('Usage: php state.php snapshot|prepare SITE DEFAULTS STATE');
    }
    if ($argv[1] === 'snapshot') {
        fusionDockerSnapshot($argv[2], $argv[3], $argv[4]);
    } else {
        fusionDockerPrepare($argv[2], $argv[3], $argv[4]);
    }
}
