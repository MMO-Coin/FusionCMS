<?php

namespace CodeIgniter\Events {
    class Events {
        public static array $queries = [];
        public static ?string $barrier = null;
        public static function trigger($event, ...$args) {
            if ($event !== 'DBQuery') return;
            $sql = (string) $args[0]; self::$queries[] = $sql;
            if (self::$barrier && preg_match('/^SELECT/i', $sql) && str_contains($sql, 'order_log')) {
                file_put_contents(self::$barrier . '/' . getmypid() . '.ready', 'ready');
                $until = microtime(true) + 10;
                while (count(glob(self::$barrier . '/*.ready')) < 2) {
                    if (microtime(true) > $until) throw new \RuntimeException('Refund barrier timed out');
                    usleep(10000);
                }
                self::$barrier = null;
            }
        }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') exit(1);
    define('BASEPATH', __DIR__); define('ENVIRONMENT', 'testing'); define('CI_DEBUG', false);
    function log_message(...$args) {}
    function config(...$args) { return (object) ['limitZeroAsAll' => true]; }
    function lang($key, $module) { return $key; }
    class CI_Model { public $db; public $realms; public $user; }
    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../application/interfaces/emulator.php';
    require __DIR__ . '/../application/emulators/trinity.php';
    require __DIR__ . '/../application/emulators/vmangos.php';
    require __DIR__ . '/../application/modules/store/models/Store_model.php';
    require __DIR__ . '/../application/modules/guild/models/Guild_model.php';
    require __DIR__ . '/../application/models/Logger_model.php';
    require __DIR__ . '/../application/libraries/ListNames.php';
    $GLOBALS['emulator'] = (new ReflectionClass(Trinity::class))->newInstanceWithoutConstructor();
    function table($name, $realm = false) { return $GLOBALS['emulator']->getTable($name); }
    function column($table, $name, $as = false, $realm = false) { return $GLOBALS['emulator']->getColumn($table, $name) . ($as ? ' AS ' . $name : ''); }
    function columns($table, $names, $realm = false) { return implode(', ', array_map(fn($name) => column($table, $name, true), $names)); }
    function query($name, $realm = false) { return $GLOBALS['emulator']->getQuery($name); }
    function checkBatch($condition, $message) { if (!$condition) throw new RuntimeException($message); echo "PASS: $message\n"; }
    function mysqlConnection($schema) {
        return new CodeIgniter\Database\MySQLi\Connection(['hostname' => getenv('FUSION_TEST_MYSQL_HOST'), 'username' => 'root',
            'password' => getenv('FUSION_TEST_MYSQL_PASSWORD'), 'port' => (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306),
            'database' => $schema, 'DBDebug' => true, 'charset' => 'utf8mb4']);
    }
    if (($argv[1] ?? '') === '--refund-worker') {
        [$unused, $mode, $schema, $nonce, $barrier] = $argv;
        if (!in_array(getenv('FUSION_TEST_MYSQL_HOST'), ['127.0.0.1', 'localhost', '::1'], true) || !preg_match('/^fusion_batch_[a-f0-9]{12}$/D', $schema)
            || !preg_match('/^[a-f0-9]{32}$/D', $nonce) || !preg_match('/^fusion-refund-[a-f0-9]{12}$/D', basename($barrier))
            || realpath(dirname($barrier)) !== realpath(sys_get_temp_dir())) throw new RuntimeException('Unsafe worker fixture');
        $db = mysqlConnection($schema);
        if (!hash_equals($nonce, $db->query('SELECT nonce FROM _batch_owner')->getRowArray()['nonce'])) throw new RuntimeException('Worker ownership mismatch');
        \CodeIgniter\Events\Events::$barrier = $barrier;
        $model = new Store_model(); $model->db = $db;
        echo json_encode(['refunded' => $model->refundOrder(777) !== null]); exit;
    }
    function exerciseBatch($db): void {
        $primary = $db instanceof CodeIgniter\Database\SQLite3\Connection ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        $db->query("CREATE TABLE order_log (id $primary, completed INT, user_id INT, vp_cost INT, dp_cost INT, cart TEXT, timestamp INT)");
        $db->query('CREATE TABLE account_data (id INT PRIMARY KEY, vp INT, dp INT)');
        $db->query('CREATE TABLE account (id INT PRIMARY KEY, username VARCHAR(40))');
        $db->query('CREATE TABLE store_items (id INT PRIMARY KEY, name VARCHAR(60), realm INT)');
        $db->query('CREATE TABLE gm_log (id INT PRIMARY KEY, action VARCHAR(50), type MEDIUMTEXT, affected INT, gm_id INT, realm INT, ip VARCHAR(45), time INT)');
        $store = new Store_model(); $store->db = $db; $store->user = new class { public function getId() { return 1; } };
        $logger = new Logger_model(); $logger->db = $db;
        $db->table('account_data')->insert(['id' => 1, 'vp' => 0, 'dp' => 0]);
        for ($id = 1; $id <= 125; $id++) {
            $db->table('order_log')->insert(['id' => $id, 'completed' => 0, 'user_id' => 1, 'vp_cost' => 3, 'dp_cost' => 5, 'cart' => '[]', 'timestamp' => time()]);
            $db->table('gm_log')->insert(['id' => $id, 'action' => 'test', 'type' => $id % 2 ? 'account' : 'characters', 'affected' => 1, 'gm_id' => 1, 'realm' => 1, 'ip' => '127.0.0.1', 'time' => 1000]);
            $db->table('account')->insert(['id' => $id, 'username' => 'User' . $id]);
        }
        checkBatch(array_map('intval', array_column($store->getOrders(0, 25, 25), 'id')) === range(100, 76), 'Orders have deterministic bounded pages');
        checkBatch(count($store->getOrders(0, 0, 99999)) === 100 && $store->countOrders(0) === 125, 'Orders cap large requests and retain matching counts');
        checkBatch(!$store->findByUserId(0, 0) && !$store->findByUserId(0, 'unknown'), 'Invalid account searches never fall back to all orders');
        foreach ([[0, 0], [-1, 10], [0, -1], [[], 10]] as [$offset, $limit]) checkBatch(!$store->getOrders(0, $offset, $limit) && !$logger->getGMLogsDb('', $offset, $limit), 'Order and GM readers reject invalid bounds');
        checkBatch(count($logger->getGMLogsDb()) === 25 && count($logger->getGMLogsDb('', 0, 99999)) === 100, 'Default and oversized GM readers remain bounded');
        checkBatch(array_map('intval', array_column($logger->getGMLogsDb('', 25, 25), 'id')) === range(100, 76), 'GM pages have an ID tie breaker');
        checkBatch(count($logger->getGMLogsDb('account', 0, 100, 1)) === $logger->countGMLogsDb('account', 1), 'GM filters share the count and read query');
        $db->table('order_log')->where('id', 125)->update(['timestamp' => time() - 8 * 86400]);
        checkBatch($store->countOrders(0, 1) === 124 && $store->countOrders(0, 2) === 0, 'Failed-order age and account filters agree');
        $one = $store->logOrder(1, 2, []); $two = $store->logOrder(1, 2, []);
        checkBatch($store->completeOrder($one) && (int) $store->getOrder($two)['completed'] === 0 && !$store->completeOrder($one), 'Completion claims only the explicit order once');
        checkBatch($store->refundOrder(1) !== null && $store->refundOrder(1) === null, 'A failed order can be refunded once');
        checkBatch((int) $db->table('account_data')->get()->getRowArray()['dp'] === 5 && $store->refundOrder($one) === null && (int) $store->getOrder(1)['completed'] === -1, 'Refund credits stored costs, retains its audit record and refuses completed orders');
        $db->table('order_log')->where('id', 2)->update(['user_id' => 999]);
        try { $store->refundOrder(2); throw new LogicException('Expected rollback'); } catch (RuntimeException $expected) {}
        checkBatch((int) $store->getOrder(2)['completed'] === 0, 'Failed balance credit rolls back the order claim');
        \CodeIgniter\Events\Events::$queries = [];
        checkBatch(count(ListNames::accounts($db, range(1, 100))) === 100 && count(\CodeIgniter\Events\Events::$queries) === 1, 'Account names are fetched in one batch query');
        foreach (['Trinity', 'Vmangos'] as $emulator) {
            $GLOBALS['emulator'] = (new ReflectionClass($emulator))->newInstanceWithoutConstructor();
            $gid = column('guild_member', 'guildid');
            $rankId = $emulator === 'Trinity' ? 'rid' : 'id'; $rankName = $emulator === 'Trinity' ? 'rname' : 'name';
            $db->query('CREATE TABLE characters (guid INT PRIMARY KEY, name VARCHAR(60), race INT, class INT, gender INT, level INT)');
            $db->query("CREATE TABLE guild_member ($gid INT, guid INT, `rank` INT)");
            $db->query("CREATE TABLE guild_rank ($gid INT, $rankId INT, $rankName VARCHAR(40), rights INT)");
            $db->query("INSERT INTO guild_rank VALUES (1, 0, 'Member', 1)");
            for ($id = 1; $id <= 125; $id++) { $db->query("INSERT INTO characters VALUES ($id, 'Hero$id', 1, 1, 0, 60)"); $db->query("INSERT INTO guild_member VALUES (1, $id, 0)"); }
            $characters = new class($db) { public function __construct(private $db) {} public function getConnection() { return $this->db; }
                public function getCharactersWhereIn($fields, $key, $values) { return $this->db->table('characters')->select($fields)->whereIn($key, $values)->get()->getResultArray(); } };
            $realms = new class($characters) { public function __construct(private $characters) {} public function realmExists($id) { return (int) $id === 1; }
                public function getRealm($id) { return $this; } public function getCharacters() { return $this->characters; } };
            $guild = new Guild_model(); $guild->realms = $realms;
            \CodeIgniter\Events\Events::$queries = [];
            checkBatch(array_map('intval', array_column($guild->getGuildMembers(1, 1, 25), 'guid')) === range(26, 50) && count(\CodeIgniter\Events\Events::$queries) === 1, "$emulator roster uses one bounded stable query");
            checkBatch($guild->countGuildMembers(1, 1) === 125 && count($guild->getGuildMembers(1, 1, 120)) === 5 && $guild->countGuildMembers(1, 2) === 0, "$emulator roster counts and final pages match");
            checkBatch(count($guild->getGuildMembers(1, 1, 0, 99999)) === 100 && !$guild->getGuildMembers(1, 1, 0, 0) && !$guild->getGuildMembers(999, 1), "$emulator roster rejects invalid requests");
            \CodeIgniter\Events\Events::$queries = [];
            $names = ListNames::characters($realms, [1 => range(1, 100), 999 => [1]]);
            checkBatch(count($names[1]) === 100 && count(\CodeIgniter\Events\Events::$queries) === 1, "$emulator character names use one batch per known realm");
            foreach (['guild_rank', 'guild_member', 'characters'] as $table) $db->query('DROP TABLE ' . $table);
        }
    }
    exerciseBatch(new CodeIgniter\Database\SQLite3\Connection(['database' => ':memory:', 'DBDebug' => true]));
    if (!getenv('FUSION_TEST_MYSQL_HOST')) exit;
    if (!in_array(getenv('FUSION_TEST_MYSQL_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) throw new RuntimeException('Only an owned loopback fixture is allowed');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $schema = 'fusion_batch_' . bin2hex(random_bytes(6)); $nonce = bin2hex(random_bytes(16));
    $admin = new mysqli(getenv('FUSION_TEST_MYSQL_HOST'), 'root', getenv('FUSION_TEST_MYSQL_PASSWORD'), '', (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306));
    $admin->query("CREATE DATABASE `$schema`"); $admin->select_db($schema);
    $admin->query('CREATE TABLE _batch_owner (nonce CHAR(32))'); $admin->query("INSERT INTO _batch_owner VALUES ('$nonce')");
    $barrier = sys_get_temp_dir() . '/fusion-refund-' . bin2hex(random_bytes(6));
    try {
        $db = mysqlConnection($schema); exerciseBatch($db);
        $db->table('order_log')->insert(['id' => 777, 'completed' => 0, 'user_id' => 1, 'vp_cost' => 7, 'dp_cost' => 11, 'cart' => '[]', 'timestamp' => time()]);
        $before = $db->table('account_data')->where('id', 1)->get()->getRowArray();
        mkdir($barrier); $workers = [];
        for ($i = 0; $i < 2; $i++) { $process = proc_open([PHP_BINARY, __FILE__, '--refund-worker', $schema, $nonce, $barrier], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes); fclose($pipes[0]); $workers[] = [$process, $pipes]; }
        $wins = 0;
        foreach ($workers as [$process, $pipes]) { $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if (proc_close($process) !== 0) throw new RuntimeException($error . $out); $wins += json_decode($out, true, 512, JSON_THROW_ON_ERROR)['refunded'] ? 1 : 0; }
        $after = $db->table('account_data')->where('id', 1)->get()->getRowArray();
        checkBatch($wins === 1 && (int) $after['vp'] - (int) $before['vp'] === 7 && (int) $after['dp'] - (int) $before['dp'] === 11, 'Two synchronized refund requests credit exactly once');
        require __DIR__ . '/next_batch_plans.php';
    } finally {
        foreach (glob($barrier . '/*.ready') as $file) unlink($file); if (is_dir($barrier)) rmdir($barrier);
        if (!hash_equals($nonce, $admin->query('SELECT nonce FROM _batch_owner')->fetch_assoc()['nonce'])) throw new RuntimeException('Refusing unrelated schema cleanup');
        $admin->query("DROP DATABASE `$schema`");
    }
}
