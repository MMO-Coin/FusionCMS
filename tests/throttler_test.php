<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/config/Services.php';

if (($argv[1] ?? '') === '--worker') {
    $limiter = new CodeIgniter\Throttle\Throttler($argv[2]);
    echo $limiter->check('concurrent', 3, 60) ? 'accepted' : 'limited';
    exit;
}

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$root = sys_get_temp_dir() . '/fusion-throttle-' . bin2hex(random_bytes(8));
define('WRITEPATH', $root . '/');
try {
    $service = App\Config\Services::throttler();
    check($service === App\Config\Services::throttler(), 'The real application factory returns a shared limiter.');
    check($service->check('user-1', 2, 60) && $service->check('user-1', 2, 60), 'Requests within the limit pass.');
    check(!App\Config\Services::throttler(false)->check('user-1', 2, 60), 'A new instance preserves the exhausted limit.');
    check($service->check('user-2', 2, 60), 'One account does not exhaust another account limit.');
    check($service->check('expiry', 1, 1) && !$service->check('expiry', 1, 1), 'The expiry fixture exhausts its limit.');
    sleep(1);
    check($service->check('expiry', 1, 1), 'The limit resets after its window expires.');
    $workers = [];
    for ($i = 0; $i < 8; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $root . '/cache/throttle'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start a rate limit worker.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $accepted = 0;
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $errors !== '') throw new RuntimeException('Rate limit worker failed: ' . $errors);
        $accepted += $output === 'accepted' ? 1 : 0;
    }
    check($accepted === 3, 'Eight concurrent workers accept exactly three requests.');
    $blocked = new CodeIgniter\Throttle\Throttler(__FILE__ . '/not-a-directory');
    check(!$blocked->check('storage-failure', 1, 60), 'Unavailable storage fails closed.');
} finally {
    foreach (glob($root . '/cache/throttle/*.json') ?: [] as $file) unlink($file);
    foreach ([$root . '/cache/throttle', $root . '/cache', $root] as $directory) {
        if (is_dir($directory)) rmdir($directory);
    }
}
