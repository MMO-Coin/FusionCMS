<?php

namespace CodeIgniter\Events {
    class Events { public static function trigger($name, ...$arguments) {} }
}
namespace App\Config {
    class Services { public static $session; public static function session() { return self::$session; } }
}
namespace {
    if (PHP_SAPI !== 'cli') exit(1);
    define('ENVIRONMENT', 'testing');
    define('CI_DEBUG', false);
    define('BASEPATH', __DIR__ . '/../system/');
    function log_message(...$arguments) {}
    function config(...$arguments) { return (object) ['limitZeroAsAll' => true]; }
    class CI_Model { public $db; }
    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../application/models/Account_session_model.php';
    require __DIR__ . '/../application/libraries/User.php';

    function check(bool $condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
        echo "PASS: $message\n";
    }
    function model($db): \Account_session_model {
        $model = new \Account_session_model();
        $model->db = $db;
        return $model;
    }
    function exercise($db): void {
        $sessions = model($db);
        check($sessions->getVersion(1) === '', 'Accounts without a reset retain their original session version.');
        foreach ([1, 1, 2] as $account) {
            $db->table('remember_me_tokens')->insert(['account_id' => $account, 'token_hash' => bin2hex(random_bytes(32))]);
        }
        $sessions->invalidate(1);
        $first = $sessions->getVersion(1);
        check(preg_match('/^[a-f0-9]{64}$/D', $first) === 1, 'Revocation records a random version independently of the password.');
        check($db->table('remember_me_tokens')->where('account_id', 1)->countAllResults() === 0
            && $db->table('remember_me_tokens')->where('account_id', 2)->countAllResults() === 1
            && $sessions->getVersion(2) === '', 'Revocation removes only the target account remembered logins and session version.');
        $sessions->invalidate(1);
        check($sessions->getVersion(1) !== $first && $db->table('account_session_versions')->countAllResults() === 1,
            'Repeated resets replace the version without revalidating any previous session.');
        try {
            $sessions->invalidate(0);
            throw new \RuntimeException('An invalid account was accepted.');
        } catch (\InvalidArgumentException $expected) {}
        echo "PASS: invalid account IDs cannot revoke sessions\n";
    }

    $db = new \CodeIgniter\Database\SQLite3\Connection(['database' => ':memory:', 'DBDebug' => true]);
    $db->query('CREATE TABLE account_session_versions (account_id INT PRIMARY KEY, version CHAR(64) NOT NULL)');
    $db->query('CREATE TABLE remember_me_tokens (account_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL)');
    exercise($db);
    $sessions = model($db);

    // Keep the real account-version model and isolate the PHP session adapter.
    \App\Config\Services::$session = new class {
        public bool $destroyed = false;
        public function get($key) { return $_SESSION[$key] ?? null; }
        public function set(array $values) { $_SESSION = array_merge($_SESSION, $values); }
        public function remove($keys) { foreach ((array) $keys as $key) unset($_SESSION[$key]); }
        public function regenerate($destroy = false) { $this->destroyed = $destroy; }
    };
    $ci = (object) [
        'account_session_model' => $sessions,
        'language' => new class { public function setLanguage($language) {} },
        'config' => new class { public function item($key) { return 'english'; } },
        'input' => new class {
            public array $cleared = [];
            public function set_cookie($name, $value) { $this->cleared[] = $name; }
        },
    ];
    $user = (new \ReflectionClass(\User::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty(\User::class, 'CI'))->setValue($user, $ci);
    $_SESSION = ['online' => true, 'uid' => 2, 'username' => 'OTHER', 'password' => 'VERIFIER', 'email' => 'other@example.invalid',
        'expansion' => 0, 'register_date' => '2026-11-01', 'last_ip' => '127.0.0.1', 'totp_secret' => 'verified-secret', 'nickname' => 'Other'];
    $user->getUserData();
    check($user->isOnline(), 'A legacy session without a version remains valid until its account is reset.');
    $sessions->invalidate(2);
    $user->getUserData();
    check(!$user->isOnline() && !isset($_SESSION['totp_secret']) && \App\Config\Services::$session->destroyed
        && count($ci->input->cleared) === 3, 'Reset revokes a legacy session, clears its 2FA state and destroys its old session ID.');
    $ci->external_account_model = new class($sessions) {
        public function __construct(private $sessions) {}
        public function getId($username = false) { return 1; }
        public function initialize($username) { $this->sessions->invalidate(1); return true; }
        public function __call($name, $arguments) {
            return ['getPassword' => 'VERIFIER', 'getUsername' => 'PLAYER', 'getEmail' => 'player@example.invalid',
                'getExpansion' => 0, 'getJoinDate' => '2026-11-01', 'getLastIp' => '127.0.0.1'][$name];
        }
    };
    $ci->internal_user_model = new class {
        public function initialize($id) {}
        public function getNickname() { return 'Player'; }
        public function getLanguage() { return 'english'; }
    };
    $oldVersion = $sessions->getVersion(1);
    $sessions->invalidate(1);
    check($user->setUserDetails('PLAYER', 'VERIFIER', $oldVersion) === 2, 'A remembered login claimed before reset cannot acquire the new session version.');
    check($user->setUserDetails('PLAYER', 'VERIFIER') === 2 && !$user->isOnline(),
        'A reset overlapping password verification rejects the login instead of issuing a fresh-version session.');

    $before = $sessions->getVersion(1);
    $db->table('remember_me_tokens')->insert(['account_id' => 1, 'token_hash' => str_repeat('a', 64)]);
    $db->query("CREATE TRIGGER fail_revocation BEFORE DELETE ON remember_me_tokens BEGIN SELECT RAISE(ABORT, 'Fixture failure'); END");
    $failed = false;
    try { $sessions->invalidate(1); } catch (\Throwable $expected) { $failed = true; }
    check($failed && $sessions->getVersion(1) === $before
        && $db->table('remember_me_tokens')->where('account_id', 1)->countAllResults() === 1,
        'A failed remembered-login deletion rolls back the session version change.');

    if (!getenv('FUSION_TEST_MYSQL_HOST')) {
        echo "SKIP: set FUSION_TEST_MYSQL_HOST to check MySQL session revocation and its migration.\n";
        exit;
    }
    if (!in_array(getenv('FUSION_TEST_MYSQL_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new \RuntimeException('Session migration tests require a disposable loopback database.');
    }
    $prefix = 'sessions_' . bin2hex(random_bytes(6)) . '_';
    $db = new \CodeIgniter\Database\MySQLi\Connection([
        'hostname' => getenv('FUSION_TEST_MYSQL_HOST'), 'username' => 'root',
        'password' => getenv('FUSION_TEST_MYSQL_PASSWORD'), 'database' => 'fusion_test',
        'port' => (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306), 'DBPrefix' => $prefix,
        'DBDebug' => true, 'charset' => 'utf8mb4',
    ]);
    $migration = file_get_contents(__DIR__ . '/../application/modules/install/SQL/updates/2026_11_01_01_account_session_versions.sql');
    $migration = str_replace('`account_session_versions`', '`' . $prefix . 'account_session_versions`', $migration);
    // The random table names isolate this test from any existing session data.
    $db->query($migration);
    $db->query('CREATE TABLE ' . $prefix . 'remember_me_tokens (account_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL) ENGINE=InnoDB');
    try {
        exercise($db);
        $before = model($db)->getVersion(1);
        $db->query($migration);
        check(model($db)->getVersion(1) === $before, 'Reapplying the MySQL migration preserves existing revocation records.');
    } finally {
        $db->query('DROP TABLE ' . $prefix . 'remember_me_tokens');
        $db->query('DROP TABLE ' . $prefix . 'account_session_versions');
    }
}
