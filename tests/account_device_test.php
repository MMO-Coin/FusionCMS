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
    class CI_Model { public $db; }
    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../application/models/Account_device_model.php';
    require __DIR__ . '/../application/models/Account_session_model.php';
    function check(bool $condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
        echo "PASS: $message\n";
    }
    function exercise($db): void {
        $devices = new \Account_device_model(); $devices->db = $db;
        $versions = new \Account_session_model(); $versions->db = $db;
        $first = str_repeat('a', 64); $second = str_repeat('b', 64); $other = str_repeat('c', 64);
        $devices->track(1, $first, '', '127.0.0.1', 'Chrome/120 Windows', 3600);
        $db->table('account_sessions')->where('session_hash', hash('sha256', $first))->update(['created_at' => '2020-01-01 00:00:00']);
        $devices->track(1, $first, '', '127.0.0.2', 'Firefox/120 Linux', 3600);
        $devices->track(1, $second, '', '127.0.0.3', 'Chrome/120 Android', 3600);
        $devices->track(2, $other, '', '127.0.0.4', 'Safari/120 Macintosh', 3600);
        $rows = $devices->sessions(1, '', $first);
        check(count($rows) === 2 && count(array_filter($rows, fn($row) => $row['current'])) === 1, 'Inventory is account-scoped and identifies exactly the current browser.');
        $current = array_values(array_filter($rows, fn($row) => $row['current']))[0];
        check($current['created_at'] === '2020-01-01 00:00:00' && $current['ip_address'] === '127.0.0.2'
            && $current['label'] === 'Firefox on Linux', 'Activity updates preserve the original sign-in time and refresh browser metadata.');
        check(!isset($current['session_hash'], $current['account_id'], $current['version'])
            && !str_contains(json_encode($rows), $first), 'Presentation data excludes registry identities, raw tokens and account versions.');
        $devices->forget(2, $first);
        check(count($devices->sessions(1, '', $first)) === 2, 'A different account cannot remove this session entry.');
        $db->table('account_sessions')->where('session_hash', hash('sha256', $second))->update(['expires_at' => date('Y-m-d H:i:s')]);
        check(count($devices->sessions(1, '', $first)) === 1 && count($devices->sessions(2, '', $other)) === 1, 'Expired entries disappear without deleting another account inventory.');
        $devices->forget(1, $first);
        check($devices->sessions(1, '', $first) === [], 'Logging out removes the current registry entry.');
        $raw = str_repeat('d', 128);
        foreach ([1, 2] as $accountId) $db->table('remember_me_tokens')->insert([
            'account_id' => $accountId, 'token_hash' => hash('sha256', $accountId === 1 ? $raw : 'other'),
            'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $remembered = $devices->remembered(1, $raw);
        check(count($remembered) === 1 && $remembered[0]['current'] && $remembered[0]['last_used_at'] === null
            && !isset($remembered[0]['token_hash']), 'Legacy remembered devices remain visible with safe defaults and no token hashes.');
        $db->table('remember_me_tokens')->where('account_id', 1)->update(['expires_at' => date('Y-m-d H:i:s')]);
        check($devices->remembered(1, $raw) === [], 'Expired remembered devices are excluded at the exact expiry boundary.');
        $devices->track(1, $first, '', '127.0.0.1', 'Chrome/120 Windows', 3600);
        $versions->invalidate(1);
        $version = $versions->getVersion(1);
        $devices->track(1, $first, '', '127.0.0.1', 'Chrome/120 Windows', 3600);
        check($devices->sessions(1, $version, $first) === [] && count($devices->sessions(2, '', $other)) === 1,
            'An old request after revocation cannot become visible under the new account version.');
        $devices->track(1, $second, $version, '127.0.0.1', 'Chrome/120 Windows', 3600);
        $devices->sessions(1, '', $first);
        check(count($devices->sessions(1, $version, $second)) === 1 && $devices->remembered(1, $raw) === []
            && count($devices->remembered(2, '')) === 1, 'Fresh sessions are visible after revocation while remembered-device removal stays account-scoped.');
        $db->table('account_sessions')->where('account_id', 1)->update(['expires_at' => date('Y-m-d H:i:s')]);
        $devices->purgeExpired();
        check($db->table('account_sessions')->where('account_id', 1)->countAllResults() === 0
            && count($devices->sessions(2, '', $other)) === 1, 'Bounded expiry cleanup removes inactive metadata and preserves active sessions.');
        check(mb_strlen(\Account_device_model::agent(str_repeat('a', 300))) === 255, 'Untrusted browser metadata is bounded.');
        foreach (['account_sessions', 'account_session_versions', 'remember_me_tokens'] as $table) $db->table($table)->emptyTable();
    }
    $db = new \CodeIgniter\Database\SQLite3\Connection(['database' => ':memory:', 'DBDebug' => true]);
    $db->query('CREATE TABLE account_sessions (session_hash CHAR(64) PRIMARY KEY, account_id INT, version CHAR(64), created_at DATETIME, last_seen_at DATETIME, expires_at DATETIME, ip_address VARCHAR(45), user_agent VARCHAR(255))');
    $db->query("CREATE TABLE remember_me_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INT, token_hash CHAR(64), created_at DATETIME, expires_at DATETIME, ip_address VARCHAR(45) DEFAULT '', user_agent VARCHAR(255) DEFAULT '', last_used_at DATETIME)");
    $db->query('CREATE TABLE account_session_versions (account_id INT PRIMARY KEY, version CHAR(64))');
    exercise($db);
    if (!getenv('FUSION_TEST_MYSQL_HOST')) { echo "SKIP: set FUSION_TEST_MYSQL_HOST to check the device migration on MySQL.\n"; exit; }
    if (!in_array(getenv('FUSION_TEST_MYSQL_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) throw new \RuntimeException('Device migration tests require a disposable loopback database.');
    $prefix = 'devices_' . bin2hex(random_bytes(6)) . '_';
    $db = new \CodeIgniter\Database\MySQLi\Connection([
        'hostname' => getenv('FUSION_TEST_MYSQL_HOST'), 'username' => 'root', 'password' => getenv('FUSION_TEST_MYSQL_PASSWORD'),
        'database' => 'fusion_test', 'port' => (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306), 'DBPrefix' => $prefix, 'DBDebug' => true, 'charset' => 'utf8mb4',
    ]);
    $db->query('CREATE TABLE ' . $prefix . 'remember_me_tokens (id INT AUTO_INCREMENT PRIMARY KEY, account_id INT, token_hash CHAR(64), created_at DATETIME, expires_at DATETIME) ENGINE=InnoDB');
    $db->query('CREATE TABLE ' . $prefix . 'account_session_versions (account_id INT PRIMARY KEY, version CHAR(64)) ENGINE=InnoDB');
    $migration = str_replace(['account_sessions', 'remember_me_tokens'], [$prefix . 'account_sessions', $prefix . 'remember_me_tokens'],
        file_get_contents(__DIR__ . '/../application/modules/install/SQL/updates/2026_11_02_01_account_devices.sql'));
    try {
        $legacy = ['account_id' => 7, 'token_hash' => str_repeat('f', 64), 'created_at' => '2020-01-01 00:00:00', 'expires_at' => '2099-01-01 00:00:00'];
        $db->table('remember_me_tokens')->insert($legacy);
        for ($repeat = 0; $repeat < 2; ++$repeat) foreach (explode(';', $migration) as $statement) if (trim($statement) !== '') $db->query($statement);
        $row = $db->table('remember_me_tokens')->where('account_id', 7)->get()->getRowArray();
        check($row['token_hash'] === $legacy['token_hash'] && $row['expires_at'] === $legacy['expires_at']
            && $row['user_agent'] === '' && $row['last_used_at'] === null, 'The repeatable MySQL migration preserves existing tokens and supplies safe metadata defaults.');
        $db->table('remember_me_tokens')->emptyTable();
        exercise($db);
    } finally {
        foreach (['account_sessions', 'account_session_versions', 'remember_me_tokens'] as $table) $db->query('DROP TABLE IF EXISTS ' . $prefix . $table);
    }
}
