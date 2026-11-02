<?php

namespace CodeIgniter\Events {
    class Events {
        public static array $queries = [];
        public static function trigger($event, ...$args) { if ($event === 'DBQuery') self::$queries[] = (string) $args[0]; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') exit(1);
    define('BASEPATH', __DIR__); define('ENVIRONMENT', 'testing'); define('CI_DEBUG', false);
    function log_message(...$args) {}
    function config(...$args) { return (object) ['limitZeroAsAll' => true]; }
    class CI_Model { public $external_account_model; }
    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../application/interfaces/emulator.php';
    require __DIR__ . '/../application/emulators/trinity.php';
    require __DIR__ . '/../application/emulators/vmangos.php';
    require __DIR__ . '/../application/models/Characters_model.php';
    require __DIR__ . '/../application/modules/online/models/Online_model.php';
    function table($name, $realm = false) { return $GLOBALS['onlineEmulator']->getTable($name); }
    function column($table, $name, $as = false, $realm = false) {
        $field = $GLOBALS['onlineEmulator']->getColumn($table, $name);
        return $field ? $field . ($as ? ' AS ' . $name : '') : false;
    }
    function columns($table, $names, $realm = false) { return implode(', ', array_map(fn($name) => column($table, $name, true, $realm), $names)); }
    function query($name, $realm = false) { return $GLOBALS['onlineEmulator']->getQuery($name); }
    function checkOnline($condition, string $message): void { if (!$condition) throw new RuntimeException($message); echo "PASS: $message\n"; }
    function exerciseOnline($db, string $emulator): void {
        $GLOBALS['onlineEmulator'] = (new ReflectionClass($emulator))->newInstanceWithoutConstructor();
        $db->query('CREATE TABLE characters (guid INT PRIMARY KEY, account INT, name VARCHAR(150), race INT, class INT, gender INT, level INT, zone INT, online INT)');
        $db->query('CREATE TABLE account (id INT PRIMARY KEY, gmlevel INT)');
        $db->query('CREATE TABLE account_access (AccountId INT, SecurityLevel INT, RealmID INT)');
        $db->query('INSERT INTO account VALUES (1,0),(2,1),(3,3)');
        $db->query('INSERT INTO account_access VALUES (3,3,-1),(3,1,-1),(2,1,2),(2,0,1)');
        for ($id = 1; $id <= 125; $id++) {
            $account = $id <= 115 ? 1 : ($id <= 120 ? 3 : 2);
            $db->table('characters')->insert(['guid' => $id, 'account' => $account, 'name' => 'Search Hero', 'race' => 1, 'class' => 1, 'gender' => 0, 'level' => 60, 'zone' => 1, 'online' => 1]);
        }
        foreach (['Literal%Name', 'Literal_Name', 'Literal!Name', "O'Name", 'MiXeD Name'] as $i => $name) {
            $db->table('characters')->insert(['guid' => 200 + $i, 'account' => 1, 'name' => $name, 'race' => 1, 'class' => 1, 'gender' => 0, 'level' => 60, 'zone' => 1, 'online' => 1]);
        }
        $db->table('characters')->insert(['guid' => 999, 'account' => 1, 'name' => 'Search Offline', 'race' => 1, 'class' => 1, 'gender' => 0, 'level' => 60, 'zone' => 1, 'online' => 0]);
        $characters = new Characters_model(['id' => 1]);
        (new ReflectionProperty(Characters_model::class, 'db'))->setValue($characters, $db);
        $online = new Online_model();
        $online->external_account_model = new class($db) { public function __construct(private $db) {} public function getConnection() { return $this->db; } };
        \CodeIgniter\Events\Events::$queries = [];
        $excluded = $online->excludedAccounts(1); sort($excluded);
        checkOnline($excluded === ($emulator === 'Trinity' ? [3] : [2, 3]) && count(\CodeIgniter\Events\Events::$queries) === 1, "$emulator staff filtering uses one mapped auth lookup");
        $realmTwo = $online->excludedAccounts(2); sort($realmTwo);
        checkOnline($realmTwo === [2, 3], "$emulator global and selected-realm grants are respected");
        $expected = array_values(array_filter(range(1, 125), static fn($id) => $id <= 115 || ($id >= 121 && !in_array(2, $excluded, true))));
        checkOnline($characters->getOnlinePlayerCount('Search', $excluded) === count($expected), "$emulator counts hide the same accounts as the rows");
        $pages = [];
        for ($offset = 0; $offset < count($expected); $offset += 10) {
            $rows = $characters->getOnlinePlayerPage($offset, 10, 'Search', $excluded);
            $page = array_map('intval', array_column($rows, 'guid'));
            checkOnline($page === array_slice($expected, $offset, 10), "$emulator returns the exact stable page at $offset");
            $pages = array_merge($pages, $page);
        }
        checkOnline(count(array_unique($pages)) === count($expected), "$emulator tied-name pages do not overlap");
        checkOnline($characters->getOnlinePlayerCount() === 130 && count($characters->getOnlinePlayers()) === 130, "$emulator legacy reads and the unrestricted count still exclude offline players");
        checkOnline(count($characters->getOnlinePlayerPage(0, 99999, '', $excluded)) === 100 && !$characters->getOnlinePlayerPage(10000, 10, '', $excluded), "$emulator oversized and empty pages remain bounded");
        foreach ([[0, 0], [0, -1], [-1, 10], [[], 10], [0, 'bad']] as [$offset, $limit]) checkOnline(!$characters->getOnlinePlayerPage($offset, $limit), "$emulator malformed bounds never request all records");
        foreach (['%', '_', '!', "O'Name", "' OR 1=1 --", 'mixed'] as $term) {
            $rows = $characters->getOnlinePlayerPage(0, 100, $term, $excluded);
            checkOnline(count($rows) === $characters->getOnlinePlayerCount($term, $excluded), "$emulator filtered counts and rows agree for $term");
            if ($term !== 'mixed') checkOnline(count($rows) === ($term === "' OR 1=1 --" ? 0 : 1), "$emulator binds literal online search $term");
        }
        \CodeIgniter\Events\Events::$queries = [];
        $characters->getOnlinePlayerPage(0, 10, '', $excluded);
        checkOnline(count(\CodeIgniter\Events\Events::$queries) === 1, "$emulator page reads do not query each player's rank");
        \CodeIgniter\Events\Events::$queries = [];
        checkOnline(!$characters->getOnlinePlayerPage(0, 10, str_repeat('x', 101)) && $characters->getOnlinePlayerCount(str_repeat('x', 101)) === 0
            && count(\CodeIgniter\Events\Events::$queries) === 0, "$emulator overlong searches do not open an unbounded query");
        $current = $GLOBALS['onlineEmulator'];
        $GLOBALS['onlineEmulator'] = new class { public function getColumn(...$args) { return null; } public function getTable(...$args) { return null; } };
        try { $online->excludedAccounts(1); throw new LogicException('Expected a mapped-rank error'); }
        catch (RuntimeException $expectedError) { echo "PASS: Missing custom rank mappings fail clearly when GM hiding is requested\n"; }
        finally { $GLOBALS['onlineEmulator'] = $current; }
        foreach (['characters', 'account', 'account_access'] as $name) $db->query('DROP TABLE ' . $name);
    }
    foreach (['Trinity', 'Vmangos'] as $emulator) exerciseOnline(new CodeIgniter\Database\SQLite3\Connection(['database' => ':memory:', 'DBDebug' => true]), $emulator);
    if (!getenv('FUSION_TEST_MYSQL_HOST')) exit;
    if (!in_array(getenv('FUSION_TEST_MYSQL_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) throw new RuntimeException('Only a disposable loopback test server is allowed');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $schema = 'fusion_online_' . bin2hex(random_bytes(6)); $nonce = bin2hex(random_bytes(16));
    $admin = new mysqli(getenv('FUSION_TEST_MYSQL_HOST'), 'root', getenv('FUSION_TEST_MYSQL_PASSWORD'), '', (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306));
    $admin->query("CREATE DATABASE `$schema`"); $admin->select_db($schema);
    $admin->query('CREATE TABLE _online_owner (nonce CHAR(32))'); $admin->query("INSERT INTO _online_owner VALUES ('$nonce')");
    try {
        foreach (['Trinity', 'Vmangos'] as $emulator) {
            $db = new CodeIgniter\Database\MySQLi\Connection(['hostname' => getenv('FUSION_TEST_MYSQL_HOST'), 'username' => 'root',
                'password' => getenv('FUSION_TEST_MYSQL_PASSWORD'), 'port' => (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306), 'database' => $schema, 'DBDebug' => true, 'charset' => 'utf8mb4']);
            exerciseOnline($db, $emulator);
        }
    } finally {
        $owner = $admin->query('SELECT nonce FROM _online_owner')->fetch_assoc();
        if (!preg_match('/^fusion_online_[a-f0-9]{12}$/D', $schema) || !hash_equals($nonce, $owner['nonce'])) throw new RuntimeException('Refusing unrelated schema cleanup');
        $admin->query("DROP DATABASE `$schema`");
    }
}
