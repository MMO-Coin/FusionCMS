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
    function checkArmory($condition, $message) {
        if (!$condition) throw new RuntimeException($message);
        echo "PASS: $message\n";
    }
    function exerciseArmory($db, string $emulator): void {
        $GLOBALS['emulator'] = (new ReflectionClass($emulator))->newInstanceWithoutConstructor();
        $fields = ['characters' => ['guid', 'name', 'race', 'gender', 'class', 'level'], 'guild' => ['guildid', 'name', 'leaderguid'],
            'guild_member' => ['guildid', 'guid'], 'item_template' => ['entry', 'name', 'ItemLevel', 'RequiredLevel', 'InventoryType', 'Quality', 'class', 'subclass']];
        foreach ($fields as $name => $names) {
            $definitions = array_map(fn($field) => column($name, $field) . ($field === 'name' ? ' VARCHAR(150)' : ' INT'), $names);
            if ($name === 'item_template') $definitions[] = 'PRIMARY KEY (' . column($name, 'entry') . ')';
            $db->query('CREATE TABLE ' . table($name) . ' (' . implode(', ', $definitions) . ')');
        }
        $insert = function($name, $row) use ($db) {
            $mapped = []; foreach ($row as $field => $value) $mapped[column($name, $field)] = $value;
            $db->table(table($name))->insert($mapped);
        };
        for ($id = 1; $id <= 125; $id++) {
            // Duplicate names and levels exercise the ID tie breakers.
            $insert('characters', ['guid' => $id, 'name' => 'Search Hero', 'race' => 1, 'gender' => 0, 'class' => 1, 'level' => 60]);
            $insert('item_template', ['entry' => $id, 'name' => 'Search Sword', 'ItemLevel' => 60, 'RequiredLevel' => 50, 'InventoryType' => 13, 'Quality' => 3, 'class' => 2, 'subclass' => 7]);
            $insert('guild', ['guildid' => $id, 'name' => 'Search Guild', 'leaderguid' => $id === 2 ? 9999 : 1]);
            if ($id !== 1) $insert('guild_member', ['guildid' => $id, 'guid' => $id]);
        }
        foreach (['Literal%Name', 'Literal_Name', 'Literal!Name', "O'Name", 'MiXeD Name'] as $i => $name) {
            $insert('characters', ['guid' => 200 + $i, 'name' => $name, 'race' => 1, 'gender' => 0, 'class' => 1, 'level' => 1]);
            $insert('item_template', ['entry' => 200 + $i, 'name' => $name, 'ItemLevel' => 10 + $i, 'RequiredLevel' => $i, 'InventoryType' => 13, 'Quality' => 2, 'class' => 2, 'subclass' => 7]);
        }
        $itemFixtures = [];
        for ($id = 300; $id < 323; $id++) {
            $item = ['entry' => $id, 'name' => ($id % 2 ? 'AncientRelic ' : 'Relic ') . $id,
                'ItemLevel' => [75, 50, 100][$id % 3], 'RequiredLevel' => $id % 60,
                'InventoryType' => $id % 20, 'Quality' => $id % 5, 'class' => $id % 8, 'subclass' => $id % 10];
            $itemFixtures[] = $item;
            $insert('item_template', $item);
        }
        usort($itemFixtures, fn($a, $b) => ($b['ItemLevel'] <=> $a['ItemLevel']) ?: ($a['entry'] <=> $b['entry']));
        $normalizeItem = function(array $row): array {
            foreach (['entry', 'ItemLevel', 'RequiredLevel', 'InventoryType', 'Quality', 'class', 'subclass'] as $field) $row[$field] = (int) $row[$field];
            return $row;
        };
        $realm = new class($db) {
            public int $expansion = 2;
            public int $worldConnections = 0;
            public function __construct(private $db) {}
            public function getExpansionId() { return $this->expansion; }
            public function getCharacters() { return $this; }
            public function getWorld() { $this->worldConnections++; return $this; }
            public function getConnection() { return $this->db; }
        };
        $model = new Armory_model();
        $model->realms = new class($realm) {
            public function __construct(private $realm) {}
            public function realmExists($id) { return $id === 1; }
            public function getRealm($id) { if ($id !== 1) throw new RuntimeException('Unknown realm accessed'); return $this->realm; }
        };
        foreach (['characters' => 'guid', 'items' => 'entry', 'guilds' => 'guildid'] as $type => $idField) {
            $get = 'get_' . $type; $count = $get . '_count';
            checkArmory($model->$count('Search', 1) === 125, "$emulator $type uses a matching aggregate count");
            foreach ([0, 10, 120] as $offset) {
                $rows = $model->$get('Search', 10, $offset, 1);
                checkArmory(array_map('intval', array_column($rows, $idField)) === range($offset + 1, min($offset + 10, 125)), "$emulator $type returns stable page $offset");
            }
            checkArmory(count($model->$get('Search', 99999, 0, 1)) === 100 && !$model->$get('Search', 10, 200, 1), "$emulator $type caps oversized and empty pages");
            foreach ([[0, 0], [-1, 10], [0, -1], [[], 10], [0, 'bad']] as [$offset, $limit]) checkArmory(!$model->$get('Search', $limit, $offset, 1), "$emulator $type rejects malformed bounds");
            checkArmory(!$model->$get('Se', 10, 0, 1) && !$model->$get('Search', 10, 0, 999) && $model->$count('Search', 999) === 0, "$emulator $type rejects short searches and unknown realms");
        }
        foreach (['Literal%', 'Literal_', 'Literal!', "O'Name", "' OR 1=1 --", 'mixed'] as $term) {
            $rows = $model->get_characters($term, 100, 0, 1);
            checkArmory(count($rows) === $model->get_characters_count($term, 1), "$emulator count and rows agree for $term");
            if ($term !== 'mixed') checkArmory(count($rows) === ($term === "' OR 1=1 --" ? 0 : 1), "$emulator binds literal search $term");
        }
        foreach (['Literal%', 'Literal_', 'Literal!', "O'Name", "' OR 1=1 --", 'mixed'] as $term) {
            $items = $model->get_items($term, 100, 0, 1);
            $count = $model->get_items_count($term, 1);
            checkArmory(count($items) === $count && $count === $model->get_characters_count($term, 1), "$emulator item search preserves literal and case matching for $term");
        }
        checkArmory($model->get_items_count('Relic', 1) === 23 && $model->get_items_count('entRelic', 1) === 11, "$emulator item search retains interior matches");
        foreach ([0, 10, 20, 23] as $offset) {
            $items = array_map($normalizeItem, $model->get_items('Relic', 10, $offset, 1));
            checkArmory($items === array_slice($itemFixtures, $offset, 10), "$emulator item page $offset preserves every field and mixed-level ordering");
        }
        checkArmory(array_map($normalizeItem, $model->get_items('  Relic  ', 100, 0, 1)) === $itemFixtures, "$emulator trimmed item searches preserve the complete result");
        $changed = $itemFixtures[array_key_last($itemFixtures)];
        $changed['name'] = 'Fresh Relic'; $changed['ItemLevel'] = 999; $changed['Quality'] = 5;
        $db->table(table('item_template'))->where(column('item_template', 'entry'), $changed['entry'])->update([
            column('item_template', 'name') => $changed['name'], column('item_template', 'ItemLevel') => $changed['ItemLevel'],
            column('item_template', 'Quality') => $changed['Quality']]);
        $freshItems = array_map($normalizeItem, $model->get_items('Relic', 10, 0, 1));
        checkArmory($freshItems[0] === $changed && $model->get_items_count('Relic', 1) === 23, "$emulator item page reads fresh fields and updated sort order");
        \CodeIgniter\Events\Events::$queries = [];
        $model->get_items('Relic', 10, 10, 1);
        checkArmory(count(\CodeIgniter\Events\Events::$queries) === 1, "$emulator item pages use one query");
        \CodeIgniter\Events\Events::$queries = [];
        $model->get_characters('Search', 10, 0, 1);
        checkArmory(count(\CodeIgniter\Events\Events::$queries) === 1, "$emulator character pages use one query");
        \CodeIgniter\Events\Events::$queries = [];
        $guilds = $model->get_guilds('Search', 10, 0, 1);
        $queries = \CodeIgniter\Events\Events::$queries;
        checkArmory(count($queries) === 2 && str_contains($queries[1], ' IN (') && !str_contains($queries[1], '125'), "$emulator member aggregation is restricted to this page in two queries");
        checkArmory($guilds[0]['GuildMemberCount'] === 0 && $guilds[1]['GuildMemberCount'] === 1 && $guilds[1]['leaderName'] === '', "$emulator keeps empty guilds and missing leaders consistent with counts");
        $realm->expansion = 5; $before = $realm->worldConnections;
        checkArmory(!$model->get_items('Search', 10, 0, 1) && $model->get_items_count('Search', 1) === 0 && $before === $realm->worldConnections, "$emulator unsupported item searches never open the world database");
        foreach (array_keys($fields) as $name) $db->query('DROP TABLE ' . table($name));
    }
    foreach (['Trinity', 'Vmangos'] as $emulator) exerciseArmory(new CodeIgniter\Database\SQLite3\Connection(['database' => ':memory:', 'DBDebug' => true]), $emulator);
    if (!getenv('FUSION_TEST_MYSQL_HOST')) exit;
    if (!in_array(getenv('FUSION_TEST_MYSQL_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) throw new RuntimeException('Only a disposable loopback test server is allowed');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $schema = 'fusion_armory_' . bin2hex(random_bytes(6)); $nonce = bin2hex(random_bytes(16));
    $admin = new mysqli(getenv('FUSION_TEST_MYSQL_HOST'), 'root', getenv('FUSION_TEST_MYSQL_PASSWORD'), '', (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306));
    $admin->query("CREATE DATABASE `$schema`"); $admin->select_db($schema);
    $admin->query('CREATE TABLE _armory_owner (nonce CHAR(32))'); $admin->query("INSERT INTO _armory_owner VALUES ('$nonce')");
    try {
        foreach (['Trinity', 'Vmangos'] as $emulator) {
            $db = new CodeIgniter\Database\MySQLi\Connection(['hostname' => getenv('FUSION_TEST_MYSQL_HOST'), 'username' => 'root',
                'password' => getenv('FUSION_TEST_MYSQL_PASSWORD'), 'port' => (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306), 'database' => $schema, 'DBDebug' => true, 'charset' => 'utf8mb4']);
            $db->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION'");
            exerciseArmory($db, $emulator);
        }
    } finally {
        $owner = $admin->query('SELECT nonce FROM _armory_owner')->fetch_assoc();
        if (!preg_match('/^fusion_armory_[a-f0-9]{12}$/D', $schema) || !hash_equals($nonce, $owner['nonce'])) throw new RuntimeException('Refusing unexpected schema cleanup');
        $admin->query("DROP DATABASE `$schema`");
    }
}
