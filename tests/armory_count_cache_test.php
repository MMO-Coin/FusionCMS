<?php

namespace CodeIgniter\Events {
    class Events {
        public static array $queries = [];
        public static function trigger($event, ...$arguments) {
            if ($event === 'DBQuery') self::$queries[] = (string) $arguments[0];
        }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') exit(1);
    require __DIR__ . '/../application/libraries/ArmoryCountCache.php';
    function checkCountCache(bool $condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
        echo "PASS: $message\n";
    }
    function waitForFile(string $path): void {
        $deadline = microtime(true) + 8;
        while (!is_file($path) && microtime(true) < $deadline) usleep(10000);
        if (!is_file($path)) throw new \RuntimeException('Worker barrier timed out');
    }
    if (($argv[1] ?? '') === '--worker') {
        [$directory, $term, $value, $ready, $release] = array_slice($argv, 2);
        $cache = new \ArmoryCountCache(true, $directory);
        $count = $cache->remember(1, 'characters', $term, function() use ($value, $ready, $release) {
            if ($ready !== '-') { file_put_contents($ready, 'ready'); waitForFile($release); }
            return (int) $value;
        });
        echo $count;
        exit;
    }
    function startCountWorker(string $directory, string $term, int $value, string $ready = '-', string $release = '-'): array {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $directory, $term, (string) $value, $ready, $release],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new \RuntimeException('Could not start cache worker');
        fclose($pipes[0]);
        return [$process, $pipes];
    }
    function finishCountWorker(array $worker, float $timeout = 8): string {
        [$process, $pipes] = $worker; $deadline = microtime(true) + $timeout;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) break;
            usleep(10000);
        } while (microtime(true) < $deadline);
        if ($status['running']) {
            proc_terminate($process);
            throw new \RuntimeException('Cache worker timed out');
        }
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $closed = proc_close($process);
        if (($status['exitcode'] >= 0 ? $status['exitcode'] : $closed) !== 0) throw new \RuntimeException('Cache worker failed: ' . $error);
        return trim($output);
    }

    $root = sys_get_temp_dir() . '/fusion-armory-count-' . bin2hex(random_bytes(8));
    $nonce = bin2hex(random_bytes(16));
    if (!mkdir($root, 0700)) throw new \RuntimeException('Could not create owned cache fixture');
    file_put_contents($root . '/owner', $nonce);
    try {
        $now = 1000.0; $loads = 0;
        $clock = function() use (&$now) { return $now; };
        $cache = new \ArmoryCountCache(true, $root . '/basic', $clock);
        $load = function() use (&$loads) { return ++$loads; };
        checkCountCache($cache->remember(1, 'characters', ' Hero ', $load) === 1
            && $cache->remember(1, 'characters', 'Hero', $load) === 1 && $loads === 1, 'Trimmed searches reuse one count');
        $now = 1029.99;
        checkCountCache($cache->remember(1, 'characters', 'Hero', $load) === 1, 'A pagination hit remains usable before expiry');
        $now = 1030.0;
        checkCountCache($cache->remember(1, 'characters', 'Hero', $load) === 2, 'Hits do not renew the exact 30-second expiry');
        checkCountCache($cache->remember(1, 'characters', 'Hero', $load, true) === 3, 'Explicit refresh bypasses the stored count');
        $zeroCalls = 0; $zero = function() use (&$zeroCalls) { $zeroCalls++; return 0; };
        checkCountCache($cache->remember(1, 'characters', 'Absent', $zero) === 0
            && $cache->remember(1, 'characters', 'Absent', $zero) === 0 && $zeroCalls === 1, 'Zero counts are valid cache hits');
        foreach ([[2,'characters','Hero'],[1,'items','Hero'],[1,'guilds','Hero'],[1,'characters','hero'],[1,'characters','Hero%'],[1,'characters','Hero_'],[1,'characters','Hero!']] as [$realm, $type, $term]) $cache->remember($realm, $type, $term, $load);
        checkCountCache($loads === 10, 'Realm, type, case and literal wildcard text have distinct keys');

        $bounded = new \ArmoryCountCache(true, $root . '/bounded', $clock);
        for ($i = 0; $i < 140; $i++) { $now += 0.001; $bounded->remember(1, 'characters', 'Term' . $i, static fn() => $i); }
        $bucket = json_decode(file_get_contents($root . '/bounded/armory_counts_v1.cache'), true);
        checkCountCache(count($bucket['entries']) === 128 && !isset($bucket['entries'][hash('sha256', "1\0characters\0Term0")]), 'Capacity evicts the oldest counts at 128 entries');
        checkCountCache(count(glob($root . '/bounded/*')) === 2 && !is_file($root . '/bounded/armory_counts_v1.tmp'), 'Unique searches use only the fixed bucket and lock files');
        $bounded->remember(1, 'characters', 'Term139', static fn() => 999, true);
        $bucket = json_decode(file_get_contents($root . '/bounded/armory_counts_v1.cache'), true);
        checkCountCache(count($bucket['entries']) === 128, 'Refreshing a full bucket does not grow it');

        foreach (['broken JSON', str_repeat('x', 65537), json_encode(['version' => 1, 'entries' => [hash('sha256', "1\0characters\0Corrupt") => ['count' => 9, 'created' => $now + 1]]])] as $bad) {
            file_put_contents($root . '/basic/armory_counts_v1.cache', $bad);
            checkCountCache($cache->remember(1, 'characters', 'Corrupt', static fn() => 7) === 7, 'Malformed, oversized or future-dated cache data falls back to a fresh count');
        }
        $disabled = new \ArmoryCountCache(false, $root . '/disabled', $clock);
        $disabledCalls = 0; $disabledLoad = function() use (&$disabledCalls) { return ++$disabledCalls; };
        checkCountCache($disabled->remember(1, 'characters', 'Hero', $disabledLoad) === 1
            && $disabled->remember(1, 'characters', 'Hero', $disabledLoad) === 2 && !file_exists($root . '/disabled'), 'Disabled caching does no filesystem work and always reads fresh counts');
        file_put_contents($root . '/not-a-directory', 'fixture');
        $unavailable = new \ArmoryCountCache(true, $root . '/not-a-directory', $clock);
        checkCountCache($unavailable->remember(1, 'characters', 'Hero', static fn() => 4) === 4, 'Unavailable cache storage does not prevent a database count');
        try { $cache->remember(1, 'characters', 'Failed', static function() { throw new \RuntimeException('database failure'); }); throw new \LogicException('Expected database failure'); }
        catch (\RuntimeException $error) { checkCountCache($error->getMessage() === 'database failure', 'Database exceptions reach the caller'); }
        checkCountCache($cache->remember(1, 'characters', 'Failed', static fn() => 5) === 5, 'A failed database read creates no cached result');
        $slow = new \ArmoryCountCache(true, $root . '/slow', $clock);
        checkCountCache($slow->remember(1, 'characters', 'Slow', function() use (&$now) { $now += 30; return 6; }) === 6
            && !is_file($root . '/slow/armory_counts_v1.cache'), 'Counts that outlast their lifetime are returned without a renewed cache entry');
        $newer = new \ArmoryCountCache(true, $root . '/newer', $clock);
        $oldResult = $newer->remember(1, 'characters', 'Overlap', function() use (&$now, $newer) {
            $now += 0.5; $newer->remember(1, 'characters', 'Overlap', static fn() => 8, true); $now += 0.5; return 7;
        }, true);
        checkCountCache($oldResult === 7 && $newer->remember(1, 'characters', 'Overlap', static fn() => 99) === 8, 'An older overlapping read cannot replace a newer count');

        $workers = [];
        for ($i = 0; $i < 8; $i++) $workers[$i] = startCountWorker($root . '/concurrent', 'Worker' . $i, $i);
        foreach ($workers as $i => $worker) checkCountCache(finishCountWorker($worker) === (string) $i, 'Concurrent worker ' . $i . ' returns its count');
        $concurrent = new \ArmoryCountCache(true, $root . '/concurrent');
        for ($i = 0; $i < 8; $i++) checkCountCache($concurrent->remember(1, 'characters', 'Worker' . $i, static fn() => 99) === $i, 'Concurrent writes preserve the other worker entries');
        $blocked = startCountWorker($root . '/concurrent', 'Blocked', 17, $root . '/count-ready', $root . '/count-release');
        waitForFile($root . '/count-ready');
        try {
            checkCountCache(finishCountWorker(startCountWorker($root . '/concurrent', 'Independent', 18), 2) === '18', 'Another worker can update the cache while a database count is waiting');
        } finally { file_put_contents($root . '/count-release', 'release'); }
        checkCountCache(finishCountWorker($blocked) === '17', 'The waiting database count completes without holding the cache lock');

        define('BASEPATH', __DIR__); define('FCPATH', $root . '/model/'); define('ENVIRONMENT', 'testing'); define('CI_DEBUG', false);
        function log_message(...$args) {}
        function config(...$args) { return (object) ['limitZeroAsAll' => true]; }
        class CI_Model { public $realms; public $config; }
        require __DIR__ . '/../vendor/autoload.php';
        require __DIR__ . '/../application/interfaces/emulator.php';
        require __DIR__ . '/../application/emulators/trinity.php';
        require __DIR__ . '/../application/modules/armory/models/Armory_model.php';
        function table($name, $realm = false) { return $GLOBALS['emulator']->getTable($name); }
        function column($table, $name, $as = false, $realm = false) { return $GLOBALS['emulator']->getColumn($table, $name) . ($as ? ' AS ' . $name : ''); }
        function columns($table, $names, $realm = false) { return implode(', ', array_map(fn($name) => column($table, $name, true, $realm), $names)); }
        $GLOBALS['emulator'] = (new \ReflectionClass('Trinity'))->newInstanceWithoutConstructor();
        $db = new \CodeIgniter\Database\SQLite3\Connection(['database' => ':memory:', 'DBDebug' => true]);
        $db->query('CREATE TABLE characters (guid INT PRIMARY KEY, name VARCHAR(150), race INT, gender INT, class INT, level INT)');
        $db->query("INSERT INTO characters VALUES (1,'Literal%Hero',1,0,1,60)");
        $realm = new class($db) {
            public int $expansion = 2; public int $worldReads = 0;
            public function __construct(public $db) {}
            public function getExpansionId() { return $this->expansion; }
            public function getCharacters() { return $this; }
            public function getWorld() { $this->worldReads++; return $this; }
            public function getConnection() { return $this->db; }
        };
        $model = new \Armory_model();
        $model->realms = new class($realm) {
            public function __construct(private $realm) {}
            public function realmExists($id) { return $id === 1; }
            public function getRealm($id) { return $this->realm; }
        };
        $model->config = new class { public bool $enabled = true; public function item($name) { return $this->enabled; } };
        \CodeIgniter\Events\Events::$queries = [];
        checkCountCache($model->get_search_count('characters', 'Literal%', 1) === 1 && count(\CodeIgniter\Events\Events::$queries) === 1, 'A cold model count uses the unchanged literal search query');
        $db->query("INSERT INTO characters VALUES (2,'Literal%Hero2',1,0,1,60)");
        \CodeIgniter\Events\Events::$queries = [];
        $count = $model->get_search_count('characters', 'Literal%', 1); $rows = $model->get_characters('Literal%', 10, 0, 1);
        checkCountCache($count === 1 && count($rows) === 2 && count(\CodeIgniter\Events\Events::$queries) === 1, 'Cached counts avoid COUNT while page rows stay fresh');
        checkCountCache($model->get_search_count('characters', 'Literal%', 1, true) === 2, 'Fresh search observes inserted realm data');
        $db->query('DELETE FROM characters WHERE guid=2');
        $modelBucketPath = FCPATH . 'writable/cache/data/search/armory_counts_v1.cache';
        $modelBucket = json_decode(file_get_contents($modelBucketPath), true);
        foreach ($modelBucket['entries'] as &$entry) $entry['created'] -= 31; unset($entry);
        file_put_contents($modelBucketPath, json_encode($modelBucket));
        checkCountCache($model->get_search_count('characters', 'Literal%', 1) === 1, 'Expired model counts observe deleted realm data');
        $persistentLock = FCPATH . 'writable/cache/armory_counts_v1.lock';
        checkCountCache(is_file($persistentLock) && !is_file(dirname($modelBucketPath) . '/armory_counts_v1.lock'), 'The production lock lives outside the administrator data-cache purge');
        unlink($modelBucketPath);
        checkCountCache(is_file($persistentLock) && $model->get_search_count('characters', 'Literal%', 1) === 1, 'Clearing cached data retains the worker lock and rebuilds the count');
        \CodeIgniter\Events\Events::$queries = []; $realm->expansion = 5;
        checkCountCache($model->get_search_count('other', 'Hero', 1) === 0 && $model->get_search_count('characters', 'ab', 1) === 0
            && $model->get_search_count('characters', 'Hero', 999) === 0 && $model->get_search_count('items', 'Hero', 1) === 0
            && \CodeIgniter\Events\Events::$queries === [] && $realm->worldReads === 0, 'Invalid searches and unsupported items do not use the cache or database');
        $model->config->enabled = false; \CodeIgniter\Events\Events::$queries = [];
        $model->get_search_count('characters', 'Literal%', 1); $model->get_search_count('characters', 'Literal%', 1);
        checkCountCache(count(\CodeIgniter\Events\Events::$queries) === 2, 'The global cache setting bypasses cached model counts');
        $model->config->enabled = true;
        $db = new \CodeIgniter\Database\SQLite3\Connection(['database' => ':memory:', 'DBDebug' => false]);
        $realm->db = $db;
        $db->query('CREATE TABLE characters (guid INT PRIMARY KEY, name VARCHAR(150), race INT, gender INT, class INT, level INT)');
        $db->query('ALTER TABLE characters RENAME TO characters_repair');
        checkCountCache($model->get_characters_count('Repair', 1) === 0 && (int) $db->error()['code'] !== 0,
            'The real driver exposes a non-throwing COUNT failure as zero with an error code');
        $beforeFailure = file_get_contents($modelBucketPath); $failed = false;
        try { $model->get_search_count('characters', 'Repair', 1); }
        catch (\RuntimeException $error) { $failed = $error->getMessage() === 'Unable to count Armory search results'; }
        checkCountCache($failed && file_get_contents($modelBucketPath) === $beforeFailure,
            'A non-throwing database failure propagates without storing a false zero');
        $db->query('ALTER TABLE characters_repair RENAME TO characters');
        $db->query("INSERT INTO characters VALUES (3,'Repair Hero',1,0,1,60)");
        checkCountCache($model->get_search_count('characters', 'Repair', 1) === 1, 'A repaired database is retried with its fresh count');
        \CodeIgniter\Events\Events::$queries = [];
        checkCountCache($model->get_search_count('characters', 'Repair', 1) === 1 && \CodeIgniter\Events\Events::$queries === [],
            'Only the successful repaired count is cached');
    } finally {
        $resolved = realpath($root);
        if ($resolved === false || dirname($resolved) !== realpath(sys_get_temp_dir()) || !preg_match('/^fusion-armory-count-[a-f0-9]{16}$/D', basename($resolved))
            || !hash_equals($nonce, (string) file_get_contents($resolved . '/owner'))) throw new \RuntimeException('Refusing unexpected cache fixture cleanup');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
        rmdir($resolved);
    }
}
