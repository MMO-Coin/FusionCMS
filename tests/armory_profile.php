<?php

namespace CodeIgniter\Events {
    class Events {
        public static bool $capture = false;
        public static array $queries = [];
        public static function trigger($event, ...$arguments) {
            if (self::$capture && $event === 'DBQuery') self::$queries[] = (string) $arguments[0];
        }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') exit(1);
    define('BASEPATH', __DIR__);
    define('ENVIRONMENT', 'testing');
    define('CI_DEBUG', false);
    function log_message(...$args) {}
    function config(...$args) { return (object) ['limitZeroAsAll' => true]; }
    class CI_Model { public $realms; }
    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../application/interfaces/emulator.php';
    require __DIR__ . '/../application/emulators/trinity.php';
    require __DIR__ . '/../application/emulators/vmangos.php';
    require __DIR__ . '/../application/modules/armory/models/Armory_model.php';

    function table($name, $realm = false) { return $GLOBALS['emulator']->getTable($name); }
    function column($table, $name, $as = false, $realm = false) { return $GLOBALS['emulator']->getColumn($table, $name) . ($as ? ' AS ' . $name : ''); }
    function columns($table, $names, $realm = false) { return implode(', ', array_map(fn($name) => column($table, $name, true, $realm), $names)); }
    function profileAssert(bool $condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
    }
    function identifier(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }
    function syntheticName(int $id): string {
        $prefix = match ($id % 100) { 0 => 'Hero', 1 => 'AncientHero', default => 'Player' };
        return $prefix . sprintf('%06d', $id);
    }
    function handlerReads($db): array {
        return array_map('intval', array_column($db->query("SHOW SESSION STATUS LIKE 'Handler_read_%'")->getResultArray(), 'Value', 'Variable_name'));
    }
    function percentile(array $values, float $fraction): float {
        sort($values, SORT_NUMERIC);
        return round($values[max(0, (int) ceil(count($values) * $fraction) - 1)], 3);
    }
    function timeSummary(array $values): array {
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);
        $median = count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
        return ['median_ms' => round($median, 3), 'p95_ms' => percentile($values, 0.95)];
    }
    function configureSession($db): string {
        $db->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION'");
        $cache = $db->query("SHOW SESSION VARIABLES LIKE 'query_cache_type'")->getRowArray();
        if (!$cache) return 'not supported by this server';
        $db->query('SET SESSION query_cache_type = OFF');
        return 'OFF for profiling session';
    }
    function seedProfile($db, int $rows, int $itemPadding, bool $itemNameIndex): array {
        $fields = ['characters' => ['guid', 'name', 'race', 'gender', 'class', 'level'],
            'guild' => ['guildid', 'name', 'leaderguid'], 'guild_member' => ['guildid', 'guid'],
            'item_template' => ['entry', 'name', 'ItemLevel', 'RequiredLevel', 'InventoryType', 'Quality', 'class', 'subclass']];
        $ddl = [];
        foreach ($fields as $name => $names) {
            $definitions = array_map(fn($field) => identifier(column($name, $field)) . ($field === 'name' ? ' VARCHAR(150) NOT NULL' : ' INT UNSIGNED NOT NULL'), $names);
            $primary = match ($name) { 'characters' => ['guid'], 'guild' => ['guildid'], 'guild_member' => ['guildid', 'guid'], default => ['entry'] };
            $definitions[] = 'PRIMARY KEY (' . implode(',', array_map(fn($field) => identifier(column($name, $field)), $primary)) . ')';
            if ($name === 'item_template' && $itemPadding) $definitions[] = 'profile_padding VARBINARY(' . $itemPadding . ') NOT NULL';
            if ($name !== 'guild_member' && ($name !== 'item_template' || $itemNameIndex)) $definitions[] = 'KEY idx_profile_name (' . identifier(column($name, 'name')) . ')';
            $ddl[$name] = 'CREATE TABLE ' . identifier(table($name)) . ' (' . implode(',', $definitions) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $db->query($ddl[$name]);
        }
        for ($start = 1; $start <= $rows; $start += 500) {
            $batch = array_fill_keys(array_keys($fields), []);
            for ($id = $start; $id < min($start + 500, $rows + 1); $id++) {
                $name = syntheticName($id);
                $batch['characters'][] = ['guid' => $id, 'name' => $name, 'race' => 1, 'gender' => 0, 'class' => 1, 'level' => 60];
                $batch['item_template'][] = ['entry' => $id, 'name' => $name, 'ItemLevel' => $id % 300 + 1, 'RequiredLevel' => 50, 'InventoryType' => 13, 'Quality' => 3, 'class' => 2, 'subclass' => 7];
                $batch['guild'][] = ['guildid' => $id, 'name' => $name, 'leaderguid' => $id === 2 ? $rows + 1 : $id];
                if ($id % 10) $batch['guild_member'][] = ['guildid' => $id, 'guid' => $id];
            }
            foreach ($batch as $name => $records) {
                $mapped = array_map(function($record) use ($name) {
                    $row = []; foreach ($record as $field => $value) $row[column($name, $field)] = $value;
                    return $row;
                }, $records);
                if ($name === 'item_template' && $itemPadding) foreach ($mapped as &$record) $record['profile_padding'] = str_repeat('p', $itemPadding);
                unset($record);
                if ($mapped) $db->table(table($name))->insertBatch($mapped);
            }
        }
        $setup = [];
        foreach (array_keys($fields) as $name) {
            $db->query('ANALYZE TABLE ' . identifier(table($name)));
            $setup[$name] = ['table' => table($name), 'rows' => (int) $db->table(table($name))->countAllResults(),
                'create_sql' => $ddl[$name], 'indexes' => $db->query('SHOW INDEX FROM ' . identifier(table($name)))->getResultArray()];
        }
        return $setup;
    }
    function profileModel($db): Armory_model {
        $realm = new class($db) {
            public function __construct(private $db) {}
            public function getExpansionId() { return 2; }
            public function getCharacters() { return $this; }
            public function getWorld() { return $this; }
            public function getConnection() { return $this->db; }
        };
        $model = new Armory_model();
        $model->realms = new class($realm) {
            public function __construct(private $realm) {}
            public function realmExists($id) { return $id === 1; }
            public function getRealm($id) { if ($id !== 1) throw new \RuntimeException('Unexpected realm'); return $this->realm; }
        };
        return $model;
    }
    function expectedIds(int $rows, string $term, string $type): array {
        $ids = [];
        for ($id = 1; $id <= $rows; $id++) if (str_contains(syntheticName($id), $term)) $ids[] = $id;
        usort($ids, $type === 'items'
            ? fn($a, $b) => (($b % 300) <=> ($a % 300)) ?: ($a <=> $b)
            : fn($a, $b) => strcmp(syntheticName($a), syntheticName($b)) ?: ($a <=> $b));
        return $ids;
    }
    function verifyResult(array $result, array $expected, string $type, int $offset): void {
        [$count, $records] = $result;
        $idField = match ($type) { 'characters' => 'guid', 'items' => 'entry', default => 'guildid' };
        profileAssert($count === count($expected), 'Unexpected matching count for ' . $type);
        profileAssert(array_map('intval', array_column($records, $idField)) === array_slice($expected, $offset, 10), 'Unexpected page order for ' . $type);
        if ($type === 'guilds') foreach ($records as $row) {
            profileAssert($row['GuildMemberCount'] === ((int) $row['guildid'] % 10 ? 1 : 0), 'Unexpected guild member count');
        }
    }
    function measure($db, callable $operation, callable $verify, int $iterations): array {
        \CodeIgniter\Events\Events::$capture = false;
        $warmStart = hrtime(true); $warmResult = $operation(); $warmMs = (hrtime(true) - $warmStart) / 1e6;
        $verify($warmResult);
        $samples = []; $queries = []; $result = null;
        for ($sample = 0; $sample < $iterations; $sample++) {
            $before = handlerReads($db);
            \CodeIgniter\Events\Events::$queries = [];
            \CodeIgniter\Events\Events::$capture = true;
            $start = hrtime(true); $result = $operation(); $elapsed = (hrtime(true) - $start) / 1e6;
            $captured = \CodeIgniter\Events\Events::$queries;
            \CodeIgniter\Events\Events::$capture = false;
            $after = handlerReads($db);
            $verify($result);
            profileAssert($captured !== [], 'No measured SQL was captured');
            if (!$queries) $queries = $captured;
            profileAssert($captured === $queries, 'Measured SQL changed between samples');
            $deltas = []; foreach ($after as $name => $value) $deltas[$name] = $value - ($before[$name] ?? 0);
            $samples[] = ['elapsed_ms' => round($elapsed, 3), 'query_count' => count($captured), 'handler_read_delta' => $deltas];
        }
        $plans = array_map(fn($sql) => ['sql' => $sql, 'explain_estimates' => $db->query('EXPLAIN ' . $sql)->getResultArray()], $queries);
        return ['warmup_ms' => round($warmMs, 3), 'timing' => timeSummary(array_column($samples, 'elapsed_ms')),
            'query_count' => count($queries), 'samples' => $samples, 'queries' => $plans, 'result' => $result];
    }
    function profileCachedCounts($db, Armory_model $model, int $rows, int $iterations): array {
        $nonce = bin2hex(random_bytes(12));
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fusion-armory-count-profile-' . $nonce;
        profileAssert(mkdir($directory, 0700), 'Could not create temporary count cache');
        profileAssert(file_put_contents($directory . '/owner', $nonce) === strlen($nonce), 'Could not mark temporary count cache');
        try {
            $cache = new ArmoryCountCache(true, $directory); $workloads = [];
            foreach (['characters', 'items', 'guilds'] as $type) {
                $expected = expectedIds($rows, 'Hero', $type);
                $load = fn() => $model->{'get_' . $type . '_count'}('Hero', 1);
                $cache->remember(1, $type, 'Hero', $load, true);
                foreach (['first' => 0, 'deep' => max(0, min(5000, intdiv(count($expected) - 1, 10) * 10))] as $page => $offset) {
                    $operation = function() use ($cache, $load, $model, $type, $offset) {
                        $total = $cache->remember(1, $type, 'Hero', $load);
                        return [$total, $offset < $total ? $model->{'get_' . $type}('Hero', 10, $offset, 1) : []];
                    };
                    $measured = measure($db, $operation, fn($result) => verifyResult($result, $expected, $type, $offset), $iterations);
                    profileAssert($measured['query_count'] === ($type === 'guilds' ? 2 : 1), 'Cached page unexpectedly repeated the search count');
                    unset($measured['result']);
                    $workloads[] = ['type' => $type, 'page' => $page, 'term' => 'Hero', 'offset' => $offset] + $measured;
                }
            }
            return ['ttl_seconds' => ArmoryCountCache::TTL, 'maximum_entries' => ArmoryCountCache::MAX_ENTRIES,
                'measurement' => 'Primed filesystem count cache plus fresh model page, with excluded warm-up. Initial count and HTTP handling are excluded.', 'workloads' => $workloads];
        } finally {
            profileAssert(dirname((string) realpath($directory)) === realpath(sys_get_temp_dir())
                && file_get_contents($directory . '/owner') === $nonce, 'Refusing unexpected count cache cleanup');
            foreach (['armory_counts_v1.cache', 'armory_counts_v1.lock', 'armory_counts_v1.tmp', 'owner'] as $file) {
                if (is_file($directory . '/' . $file)) profileAssert(unlink($directory . '/' . $file), 'Could not remove temporary count cache file');
            }
            profileAssert(rmdir($directory), 'Could not remove temporary count cache directory');
        }
    }
    function itemComparisonBuilder($db, string $term) {
        $escape = $db->likeEscapeChar;
        $term = strtr($term, [$escape => $escape . $escape, '%' => $escape . '%', '_' => $escape . '_']);
        return $db->table(table('item_template'))->like(column('item_template', 'name'), $term, 'both', true);
    }
    function itemComparisonPage($db, string $term, int $offset, bool $derived): array {
        $fields = ['entry', 'name', 'ItemLevel', 'RequiredLevel', 'InventoryType', 'Quality', 'class', 'subclass'];
        $entry = column('item_template', 'entry'); $level = column('item_template', 'ItemLevel');
        $builder = itemComparisonBuilder($db, $term);
        if (!$derived) return $builder->select(columns('item_template', $fields))
            ->orderBy($level, 'DESC')->orderBy($entry, 'ASC')->limit(10, $offset)->get()->getResultArray();
        $page = $builder->select(identifier($entry) . ' AS armory_entry, ' . identifier($level) . ' AS armory_level', false)
            ->orderBy($level, 'DESC')->orderBy($entry, 'ASC')->limit(10, $offset);
        $select = implode(', ', array_map(fn($field) => 'armory_item.' . identifier(column('item_template', $field)) . ' AS ' . identifier($field), $fields));
        return $db->newQuery()->fromSubquery($page, 'armory_page')
            ->join(table('item_template') . ' armory_item', 'armory_item.' . identifier($entry) . ' = armory_page.armory_entry', 'inner', false)
            ->select($select, false)->orderBy('armory_page.armory_level', 'DESC')->orderBy('armory_page.armory_entry', 'ASC')->get()->getResultArray();
    }
    function verifyItemComparison(array $records, array $expected, int $offset): void {
        $expected = array_slice($expected, $offset, 10);
        profileAssert(array_map('intval', array_column($records, 'entry')) === $expected, 'Item candidate changed page membership or order');
        foreach ($records as $index => $record) {
            $id = $expected[$index];
            $values = ['entry' => $id, 'name' => syntheticName($id), 'ItemLevel' => $id % 300 + 1,
                'RequiredLevel' => 50, 'InventoryType' => 13, 'Quality' => 3, 'class' => 2, 'subclass' => 7];
            profileAssert(array_keys($record) === array_keys($values), 'Item candidate changed selected fields');
            foreach ($values as $field => $value) profileAssert(($field === 'name' ? $record[$field] : (int) $record[$field]) === $value, 'Item candidate changed field ' . $field);
        }
    }
    function measureItemCandidates($db, array $operations, callable $verify, int $iterations): array {
        $measurements = [];
        \CodeIgniter\Events\Events::$capture = false;
        foreach ($operations as $name => $operation) {
            $start = hrtime(true); $result = $operation(); $warmMs = (hrtime(true) - $start) / 1e6;
            $verify($result);
            $measurements[$name] = ['warmup_ms' => round($warmMs, 3), 'samples' => [], 'sql' => []];
        }
        for ($sample = 0; $sample < $iterations; $sample++) {
            $order = array_keys($operations);
            if ($sample % 2) $order = array_reverse($order);
            foreach ($order as $position => $name) {
                $before = handlerReads($db);
                \CodeIgniter\Events\Events::$queries = [];
                \CodeIgniter\Events\Events::$capture = true;
                $start = hrtime(true); $result = $operations[$name](); $elapsed = (hrtime(true) - $start) / 1e6;
                $queries = \CodeIgniter\Events\Events::$queries;
                \CodeIgniter\Events\Events::$capture = false;
                $after = handlerReads($db);
                $verify($result);
                profileAssert(count($queries) === 1, 'Item candidate must execute one statement');
                if (!$measurements[$name]['sql']) $measurements[$name]['sql'] = $queries;
                profileAssert($measurements[$name]['sql'] === $queries, 'Item candidate SQL changed between samples');
                $delta = []; foreach ($after as $counter => $value) $delta[$counter] = $value - ($before[$counter] ?? 0);
                $measurements[$name]['samples'][] = ['elapsed_ms' => round($elapsed, 3), 'query_count' => count($queries),
                    'execution_position' => $position + 1, 'handler_read_delta' => $delta];
            }
        }
        foreach ($measurements as &$measured) {
            $measured['timing'] = timeSummary(array_column($measured['samples'], 'elapsed_ms'));
            $measured['query_count'] = count($measured['sql']);
            $measured['queries'] = array_map(fn($sql) => ['sql' => $sql, 'explain_estimates' => $db->query('EXPLAIN ' . $sql)->getResultArray()], $measured['sql']);
            unset($measured['sql']);
        }
        unset($measured);
        return $measurements;
    }
    function profileItemCandidates($db, int $rows, int $iterations): array {
        $workloads = [];
        foreach (['selective' => 'Hero', 'common' => 'Player', 'interior' => 'entHero', 'no_match' => 'NeverMatched'] as $case => $term) {
            $expected = expectedIds($rows, $term, 'items');
            $offsets = ['first' => 0];
            if ($expected) $offsets['deep'] = max(0, min(5000, intdiv(count($expected) - 1, 10) * 10));
            foreach ($offsets as $page => $offset) {
                $operations = ['original' => fn() => itemComparisonPage($db, $term, $offset, false),
                    'derived_id_page' => fn() => itemComparisonPage($db, $term, $offset, true)];
                $workloads[] = ['case' => $case, 'page' => $page, 'term' => $term, 'offset' => $offset,
                    'matching_rows' => count($expected), 'returned_rows' => count(array_slice($expected, $offset, 10)),
                    'candidates' => measureItemCandidates($db, $operations, fn($records) => verifyItemComparison($records, $expected, $offset), $iterations)];
            }
        }
        return ['measurement' => 'Item page only, without count or HTTP. One excluded warm-up per candidate; measured candidates alternate execution order each sample. Each statement must return the same ordered IDs and all eight current item fields.',
            'semantics' => 'Both candidates preserve escaped contains matching and ItemLevel DESC, entry ASC order. The derived candidate selects a bounded ID/level page, then joins its item details in the same statement.',
            'workloads' => $workloads];
    }
    function profileEmulator($db, string $emulator, int $rows, int $iterations, int $itemPadding, bool $itemNameIndex): array {
        $GLOBALS['emulator'] = (new \ReflectionClass($emulator))->newInstanceWithoutConstructor();
        fwrite(STDERR, "Seeding $emulator: $rows rows per searchable table\n");
        $setup = seedProfile($db, $rows, $itemPadding, $itemNameIndex); $model = profileModel($db); $workloads = [];
        foreach (['characters', 'items', 'guilds'] as $type) {
            foreach (['selective' => 'Hero', 'common' => 'Player', 'interior' => 'entHero', 'no_match' => 'NeverMatched'] as $case => $term) {
                $expected = expectedIds($rows, $term, $type);
                $offsets = ['first' => 0];
                if (in_array($case, ['selective', 'common'], true)) $offsets['deep'] = max(0, min(5000, intdiv(count($expected) - 1, 10) * 10));
                foreach ($offsets as $page => $offset) {
                    $operation = function() use ($model, $type, $term, $offset) {
                        $total = $model->{'get_' . $type . '_count'}($term, 1);
                        return [$total, $offset < $total ? $model->{'get_' . $type}($term, 10, $offset, 1) : []];
                    };
                    $measured = measure($db, $operation, fn($result) => verifyResult($result, $expected, $type, $offset), $iterations);
                    [$total, $records] = $measured['result']; unset($measured['result']);
                    $workloads[] = ['type' => $type, 'case' => $case, 'page' => $page, 'term' => $term, 'offset' => $offset,
                        'matching_rows' => $total, 'returned_rows' => count($records)] + $measured;
                }
            }
        }
        $comparison = [];
        foreach (['contains' => 'both', 'prefix_candidate' => 'after'] as $case => $side) {
            $operation = fn() => (int) $db->table(table('characters'))->like(column('characters', 'name'), 'Hero', $side, true)->countAllResults();
            $expected = $case === 'contains' ? count(expectedIds($rows, 'Hero', 'characters')) : intdiv($rows, 100);
            $measured = measure($db, $operation, fn($count) => profileAssert($count === $expected, 'Unexpected comparison count'), $iterations);
            $comparison[$case] = ['matching_rows' => $measured['result']] + $measured; unset($comparison[$case]['result']);
        }
        $comparison['semantics'] = 'Analytical comparison only. Prefix matching excludes interior matches and is not an equivalent replacement for the application query.';
        $comparison['interior_matches_lost_by_prefix'] = $comparison['contains']['matching_rows'] - $comparison['prefix_candidate']['matching_rows'];
        $cached = profileCachedCounts($db, $model, $rows, $iterations);
        $itemCandidates = profileItemCandidates($db, $rows, $iterations);
        foreach (array_keys($setup) as $name) $db->query('DROP TABLE ' . identifier(table($name)));
        return ['setup' => $setup, 'workloads' => $workloads, 'prefix_comparison' => $comparison, 'count_cache' => $cached, 'item_query_comparison' => $itemCandidates];
    }

    try {
        $options = ['rows' => 10000, 'iterations' => 5, 'output' => null, 'item-padding' => 0, 'item-name-index' => 1]; $seen = [];
        foreach (array_slice($argv, 1) as $argument) {
            if ($argument === '--help') {
                echo "Manual synthetic Armory profile on an owned loopback test database.\n",
                    "Usage: php tests/armory_profile.php [--rows=10000] [--iterations=5] [--item-padding=0] [--item-name-index=1] [--output=report.json]\n",
                    "Requires FUSION_TEST_MYSQL_HOST=127.0.0.1 or ::1; uses FUSION_TEST_MYSQL_PORT/PASSWORD.\n",
                    "Rows: 1000..200000 per searchable table. Iterations: 3..15. Item padding: 0..4096 bytes of unselected synthetic data per row.\n",
                    "Item name index: 0 or 1. Other tables retain their name indexes. No timing pass/fail threshold.\n";
                exit;
            }
            profileAssert(preg_match('/^--(rows|iterations|output|item-padding|item-name-index)=(.+)$/D', $argument, $match) === 1, 'Unknown or malformed argument');
            profileAssert(!isset($seen[$match[1]]), 'Duplicate argument'); $seen[$match[1]] = true;
            $options[$match[1]] = $match[2];
        }
        foreach (['rows' => [1000, 200000], 'iterations' => [3, 15], 'item-padding' => [0, 4096], 'item-name-index' => [0, 1]] as $key => [$min, $max]) {
            profileAssert(preg_match('/^[0-9]+$/D', (string) $options[$key]) === 1 && $options[$key] >= $min && $options[$key] <= $max, 'Invalid ' . $key);
            $options[$key] = (int) $options[$key];
        }
        if ($options['output'] !== null) profileAssert(is_dir(dirname($options['output'])) && !file_exists($options['output']), 'Output directory must exist and output file must not already exist');
        $host = getenv('FUSION_TEST_MYSQL_HOST');
        profileAssert(in_array($host, ['127.0.0.1', '::1'], true), 'Explicit literal-loopback FUSION_TEST_MYSQL_HOST is required');
        $port = getenv('FUSION_TEST_MYSQL_PORT') ?: '3306';
        profileAssert(preg_match('/^[0-9]+$/D', $port) === 1 && (int) $port >= 1 && (int) $port <= 65535, 'Invalid MySQL port');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $admin = new \mysqli($host, 'root', getenv('FUSION_TEST_MYSQL_PASSWORD') ?: '', '', (int) $port);
        $schema = 'fusion_armory_profile_' . bin2hex(random_bytes(6)); $nonce = bin2hex(random_bytes(16));
        $owned = false;
        try {
            $admin->query('CREATE DATABASE ' . identifier($schema) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $admin->select_db($schema);
            $admin->query('CREATE TABLE _armory_profile_owner (nonce CHAR(32) NOT NULL)');
            $admin->query("INSERT INTO _armory_profile_owner VALUES ('$nonce')"); $owned = true;
            $db = new \CodeIgniter\Database\MySQLi\Connection(['hostname' => $host, 'username' => 'root',
                'password' => getenv('FUSION_TEST_MYSQL_PASSWORD') ?: '', 'port' => (int) $port, 'database' => $schema,
                'DBDebug' => true, 'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_unicode_ci']);
            $cache = configureSession($db);
            $report = ['generated_at_utc' => gmdate('c'), 'server_version' => $admin->server_info, 'php_version' => PHP_VERSION,
                'rows_per_searchable_table' => $options['rows'], 'iterations' => $options['iterations'], 'page_size' => 10,
                'query_cache' => $cache, 'collation' => 'utf8mb4_unicode_ci', 'sql_mode' => 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION',
                'item_fixture' => ['unselected_padding_bytes_per_row' => $options['item-padding'], 'name_index' => (bool) $options['item-name-index'],
                    'description' => 'Synthetic sensitivity scenarios only; padding and index choices do not reproduce a particular realm database.'],
                'innodb_buffer_pool_size_bytes' => (int) $db->query("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'")->getRowArray()['Value'],
                'measurement' => 'Controller-equivalent bounded database path: count first, then the actual model page only when offset is below total. Excludes HTTP handling, result formatting and icons.',
                'limits' => ['Synthetic deterministic data with minimal emulator-shaped tables and explicit indexes, not a production dump.',
                    'One excluded warm-up per workload; single-client warm-buffer samples include PHP model and database work.',
                    'EXPLAIN rows are optimizer estimates; Handler_read counters measure handler activity, not exact rows examined.',
                    'Results do not establish production capacity. No application search behavior or game schema is changed.'], 'emulators' => []];
            foreach (['Trinity', 'Vmangos'] as $emulatorClass) $report['emulators'][$emulatorClass] = profileEmulator($db, $emulatorClass, $options['rows'], $options['iterations'], $options['item-padding'], (bool) $options['item-name-index']);
            $db->close();
        } finally {
            if ($owned) {
                $owner = $admin->query('SELECT nonce FROM _armory_profile_owner')->fetch_all(MYSQLI_ASSOC);
                profileAssert(preg_match('/^fusion_armory_profile_[a-f0-9]{12}$/D', $schema) === 1 && count($owner) === 1 && hash_equals($nonce, $owner[0]['nonce']), 'Refusing unexpected schema cleanup');
                $admin->query('DROP DATABASE ' . identifier($schema));
            }
            $admin->close();
        }
        $report['owned_schema_removed'] = true;
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if ($options['output'] === null) echo $json;
        else {
            profileAssert(file_put_contents($options['output'], $json, LOCK_EX) !== false, 'Could not write profile report');
            fwrite(STDERR, 'Saved profile to ' . $options['output'] . PHP_EOL);
        }
    } catch (\Throwable $error) {
        fwrite(STDERR, 'Armory profile failed: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
