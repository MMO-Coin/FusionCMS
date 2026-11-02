<?php

namespace CodeIgniter\Events {
    class Events { public static function trigger($name, ...$arguments) {} }
}
namespace {
    if (PHP_SAPI !== 'cli') exit(1);
    define('ENVIRONMENT', 'testing');
    define('CI_DEBUG', false);
    function log_message(...$arguments) {}
    function config(...$arguments) { return (object) ['limitZeroAsAll' => true]; }
    class CI_Model { public $db; public $user; }
    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../application/models/Logger_model.php';
    require __DIR__ . '/../application/modules/admin/models/Logging_model.php';
    require __DIR__ . '/../application/modules/admin/models/Accounts_model.php';
    require __DIR__ . '/../application/modules/admin/models/Dashboard_model.php';

    function checkLogs(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
        echo "PASS: $message\n";
    }
    function logIds($rows): array { return array_map(static fn($row) => (int) $row['id'], (array) $rows); }
    function logModels($db): array {
        $logging = new Logging_model();
        $logging->user = new class { public function getId($name) { return $name === 'Alice' ? 1 : 0; } };
        $accounts = (new ReflectionClass(Accounts_model::class))->newInstanceWithoutConstructor();
        $logger = new Logger_model();
        $dashboard = new Dashboard_model();
        foreach ([$logging, $accounts, $logger, $dashboard] as $model) $model->db = $db;
        return [$logging, $accounts, $logger, $dashboard];
    }
    function createLogTables($db): void {
        $db->query('CREATE TABLE logs (id INTEGER PRIMARY KEY, user_id INT NOT NULL, module VARCHAR(32), type VARCHAR(32), event VARCHAR(32), message TEXT, custom TEXT, ip VARCHAR(45), time INT NOT NULL)');
        $db->query('CREATE INDEX user_id ON logs (user_id)');
        $db->query('CREATE TABLE email_log (id INTEGER PRIMARY KEY, uid INT, email VARCHAR(255), subject VARCHAR(255), message TEXT, timestamp VARCHAR(255))');
        $db->query('CREATE TABLE comments (id INTEGER PRIMARY KEY, article_id INT NOT NULL, author_id INT, content TEXT)');
    }
    function exerciseLogs($db): void {
        $rows = $emails = [];
        for ($id = 1; $id <= 160; $id++) {
            $rows[] = ['id' => $id, 'user_id' => $id % 4, 'module' => $id % 2 ? 'auth' : 'admin', 'type' => 'user', 'event' => 'login',
                'message' => 'Entry ' . $id, 'custom' => '{}', 'ip' => '192.0.2.' . ($id % 4), 'time' => 1000];
            $emails[] = ['id' => $id, 'uid' => $id % 4, 'email' => 'user' . ($id % 4) . '@example.invalid', 'subject' => $id % 3 === 0 ? 'Target' : 'Other', 'message' => 'Body', 'timestamp' => '1000'];
        }
        $db->table('logs')->insertBatch($rows);
        $db->table('email_log')->insertBatch($emails);
        [$logging, $accounts, $logger, $dashboard] = logModels($db);
        $expected = array_values(array_filter(range(160, 1), static fn($id) => $id % 4 === 1));
        foreach ([$logging, $accounts] as $model) {
            $all = [];
            for ($offset = 0; $offset < 40; $offset += 10) {
                $page = logIds($model->getLogs(1, $offset, 10));
                checkLogs(count($page) === 10, get_class($model) . ' returns exactly ten rows at offset ' . $offset);
                $all = array_merge($all, $page);
            }
            checkLogs($all === $expected && count(array_unique($all)) === 40, get_class($model) . ' has stable tied timestamps and no overlapping pages');
            foreach ([[-1, 10], [0, 0], [0, -1], ['bad', 10], [0, '2.5'], [[], 10]] as [$offset, $limit]) checkLogs(!$model->getLogs(1, $offset, $limit), 'Malformed pagination never becomes an unbounded query');
        }
        checkLogs(count($logging->findLogs()) === 25 && count($logger->getLogsDb()) === 25, 'Default log reads are bounded');
        checkLogs(count($logging->findLogs('', '', 0, 100000)) === 100 && count($logger->getLogsDb('', 0, 100000)) === 100, 'Oversized log pages are capped');
        checkLogs(!$logger->getLogsDb('', 0, 0) && !$logger->getLogsDb('', -1, 10), 'Logger rejects zero limits and negative offsets');
        checkLogs($logging->countUserLogs(1) === 40 && $logging->countLogs('Alice', 'auth') === 40, 'Counts use the requested account and filters');
        checkLogs(!$logging->findLogs('Alice', 'admin') && !$logging->findLogs('unknown-user') && $logging->countLogs('0') === 40, 'Module, unknown-user and guest searches stay distinct');
        checkLogs(count($logging->findLogs('192.0.2.2', 'admin', 10, 10)) === 10, 'IP search remains filtered on subsequent pages');
        checkLogs(!array_intersect(logIds($logging->findLogs('Alice', 'auth', 0, 10)), logIds($logging->findLogs('Alice', 'auth', 10, 10))), 'Filtered search pages do not overlap');
        checkLogs(!$logging->findLogs("' OR 1=1 --") && !$logging->findLogs('', "auth' OR 1=1 --"), 'Search values remain bound query parameters');
        checkLogs(logIds($dashboard->getEmailLogs()) === range(160, 136), 'Email logs return the newest 25 entries');
        checkLogs(logIds($dashboard->getEmailLogs(25, 25)) === range(135, 111), 'Email pagination reads only the requested next page');
        checkLogs($dashboard->countEmailLogs('Target') === 53 && count($dashboard->getEmailLogs(25, 25, 'Target')) === 25, 'Email search count and subsequent pages use the same filter');
        checkLogs(count($dashboard->getEmailLogs(0, 99999)) === 100 && !$dashboard->getEmailLogs(0, 0), 'Email reads cannot request unlimited rows');
        checkLogs($dashboard->countEmailLogs('user1@example.invalid') === 40 && $dashboard->countEmailLogs('1') === 40, 'Email and user ID search work');
        checkLogs($dashboard->countEmailLogs('%') === 0, 'Email search treats wildcard input literally');
    }

    $sqlite = new CodeIgniter\Database\SQLite3\Connection(['database' => ':memory:', 'DBDebug' => true]);
    createLogTables($sqlite);
    exerciseLogs($sqlite);

    if (!getenv('FUSION_TEST_MYSQL_HOST')) { echo "MySQL plan and migration checks require the disposable loopback test server.\n"; exit; }
    if (!in_array(getenv('FUSION_TEST_MYSQL_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) throw new RuntimeException('Only a disposable loopback server is allowed');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $schema = 'fusion_opt_' . bin2hex(random_bytes(6));
    $nonce = bin2hex(random_bytes(16));
    $admin = new mysqli(getenv('FUSION_TEST_MYSQL_HOST'), 'root', getenv('FUSION_TEST_MYSQL_PASSWORD'), '', (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306));
    $admin->query("CREATE DATABASE `$schema`");
    $admin->select_db($schema);
    $admin->query('CREATE TABLE _optimization_owner (nonce CHAR(32))');
    $admin->query("INSERT INTO _optimization_owner VALUES ('$nonce')");
    try {
        $mysql = new CodeIgniter\Database\MySQLi\Connection(['hostname' => getenv('FUSION_TEST_MYSQL_HOST'), 'username' => 'root',
            'password' => getenv('FUSION_TEST_MYSQL_PASSWORD'), 'port' => (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306),
            'database' => $schema, 'DBDebug' => true, 'charset' => 'utf8mb4']);
        createLogTables($mysql);
        exerciseLogs($mysql);
        $mysql->table('logs')->emptyTable();
        for ($start = 1; $start <= 12000; $start += 200) {
            $logs = $comments = [];
            for ($id = $start; $id < $start + 200; $id++) {
                $logs[] = ['id' => $id, 'user_id' => $id % 200, 'module' => 'auth', 'type' => 'user', 'event' => 'login', 'message' => str_repeat('log ', 80), 'custom' => '{}', 'ip' => '192.0.2.1', 'time' => intdiv($id, 5)];
                $comments[] = ['id' => $id, 'article_id' => $id % 200, 'author_id' => 1, 'content' => str_repeat('comment ', 20)];
            }
            $mysql->table('logs')->insertBatch($logs);
            $mysql->table('comments')->insertBatch($comments);
        }
        $queries = [
            'account logs' => ['SELECT * FROM logs WHERE user_id = 17 ORDER BY time DESC, id DESC LIMIT 10', 'idx_logs_user_time_id'],
            'website logs' => ['SELECT * FROM logs ORDER BY time DESC, id DESC LIMIT 10', 'idx_logs_time_id'],
            'article comments' => ['SELECT * FROM comments WHERE article_id = 17 ORDER BY id DESC', 'idx_comments_article_id'],
        ];
        $before = [];
        foreach ($queries as $name => [$query]) $before[$name] = $mysql->query('EXPLAIN ' . $query)->getRowArray();
        $migration = file_get_contents(__DIR__ . '/../application/modules/install/SQL/updates/2026_11_02_02_log_query_indexes.sql');
        $apply = static function() use ($admin, $migration): void {
            $admin->multi_query($migration);
            do { if ($result = $admin->store_result()) $result->free(); } while ($admin->more_results() && $admin->next_result());
        };
        $apply();
        $mysql->query('ANALYZE TABLE logs, comments')->getResultArray();
        foreach ($queries as $name => [$query, $index]) {
            $after = $mysql->query('EXPLAIN ' . $query)->getRowArray();
            checkLogs($after['key'] === $index && !str_contains((string) ($after['Extra'] ?? ''), 'filesort'), $name . ' uses its verified index without a filesort');
            echo 'PLAN ' . $name . ': ' . json_encode(['before' => array_intersect_key($before[$name], array_flip(['type', 'key', 'rows', 'Extra'])), 'after' => array_intersect_key($after, array_flip(['type', 'key', 'rows', 'Extra']))]) . "\n";
        }
        $apply();
        checkLogs($mysql->table('logs')->countAllResults() === 12000 && $mysql->table('comments')->countAllResults() === 12000, 'Repeating the migration preserves all rows');
        $mysql->query('ALTER TABLE logs RENAME INDEX idx_logs_user_time_id TO custom_user_time_id');
        $mysql->query('ALTER TABLE logs RENAME INDEX idx_logs_time_id TO custom_time_id');
        $mysql->query('ALTER TABLE comments RENAME INDEX idx_comments_article_id TO custom_article_id');
        $apply();
        $duplicates = $mysql->query("SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND INDEX_NAME IN ('idx_logs_user_time_id', 'idx_logs_time_id', 'idx_comments_article_id')", [$schema])->getRowArray();
        checkLogs((int) $duplicates['total'] === 0, 'Equivalent custom indexes are reused instead of duplicated');
        $mysql->query('ALTER TABLE logs DROP INDEX custom_time_id, ADD INDEX custom_mixed_time (time ASC, id DESC)');
        $apply();
        $mixedPlan = $mysql->query('EXPLAIN SELECT * FROM logs ORDER BY time DESC, id DESC LIMIT 10')->getRowArray();
        checkLogs($mixedPlan['key'] === 'idx_logs_time_id', 'A mixed-direction index is not mistaken for an equivalent ordering');
        $mysql->query('ALTER TABLE logs DROP INDEX idx_logs_time_id, ADD INDEX custom_descending_time (time DESC, id DESC)');
        $apply();
        $descending = $mysql->query("SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'logs' AND INDEX_NAME = 'idx_logs_time_id'", [$schema])->getRowArray();
        checkLogs((int) $descending['total'] === 0, 'An equivalent descending index is reused');
    } finally {
        $owner = $admin->query('SELECT nonce FROM _optimization_owner')->fetch_assoc();
        if (!preg_match('/^fusion_opt_[a-f0-9]{12}$/D', $schema) || !hash_equals($nonce, $owner['nonce'])) throw new RuntimeException('Refusing unexpected schema cleanup');
        $admin->query("DROP DATABASE `$schema`");
    }
}
