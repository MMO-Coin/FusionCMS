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
    class CI_Model { public $db; public $config; public $account_session_model; }
    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../application/models/Cms_model.php';
    require __DIR__ . '/../application/models/Account_session_model.php';

    function check(bool $condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
        echo "PASS: $message\n";
    }
    function model($db, string $class = \Cms_model::class): \Cms_model {
        $model = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $model->db = $db;
        $model->account_session_model = new \Account_session_model();
        $model->account_session_model->db = $db;
        $model->config = new class {
            public function item($key) { return $key === 'cookie_expire' ? 2592000 : null; }
        };
        return $model;
    }
    function mysql(string $prefix) {
        if (!preg_match('/^remember_[a-f0-9]{12}_$/D', $prefix)
            || !in_array(getenv('FUSION_TEST_MYSQL_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new \RuntimeException('Remember-me races require an isolated loopback database.');
        }
        return new \CodeIgniter\Database\MySQLi\Connection([
            'hostname' => getenv('FUSION_TEST_MYSQL_HOST'), 'username' => 'root',
            'password' => getenv('FUSION_TEST_MYSQL_PASSWORD'), 'database' => 'fusion_test',
            'port' => (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306), 'DBPrefix' => $prefix,
            'DBDebug' => true, 'charset' => 'utf8mb4',
        ]);
    }
    class OverlappingRemember extends \Cms_model {
        public string $barrier;
        public string $mode;
        protected function findRememberMeToken(string $hash): ?array {
            $row = parent::findRememberMeToken($hash);
            if (!$row) return null;
            file_put_contents($this->barrier . '/' . getmypid() . '.ready', 'ready');
            $until = microtime(true) + 10;
            while ($this->mode === 'rotate' ? count(glob($this->barrier . '/*.ready')) < 2 : !is_file($this->barrier . '/release')) {
                if (microtime(true) > $until) throw new \RuntimeException('Remember-me race barrier timed out.');
                usleep(2000);
            }
            return $row;
        }
    }
    if (($argv[1] ?? '') === 'worker') {
        $worker = model(mysql($argv[2]), OverlappingRemember::class);
        $worker->barrier = $argv[3];
        $worker->mode = $argv[4];
        echo $worker->validateRememberMeToken(str_repeat('a', 128)) ? '1' : '0';
        exit;
    }
    function seed($db): void {
        $db->table('remember_me_tokens')->insert([
            'account_id' => 1, 'token_hash' => hash('sha256', str_repeat('a', 128)),
            'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    function startWorker(string $prefix, string $barrier, string $mode): array {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $prefix, $barrier, $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Cannot start the remember-me worker.');
        fclose($pipes[0]);
        return [$process, $pipes];
    }
    function finishWorker(array $worker): string {
        [$process, $pipes] = $worker;
        $result = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new \RuntimeException('Remember-me worker failed: ' . $error);
        return $result;
    }

    $columns = " (account_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, ip_address VARCHAR(45) NOT NULL DEFAULT '', user_agent VARCHAR(255) NOT NULL DEFAULT '', last_used_at DATETIME NULL)";
    $db = new \CodeIgniter\Database\SQLite3\Connection(['database' => ':memory:', 'DBDebug' => true]);
    $db->query('CREATE TABLE remember_me_tokens' . str_replace(' (', ' (id INTEGER PRIMARY KEY AUTOINCREMENT, ', $columns));
    $db->query('CREATE TABLE account_session_versions (account_id INT PRIMARY KEY, version CHAR(64) NOT NULL)');
    $remember = model($db);
    $raw = $remember->issueRememberMeToken(1);
    $row = $db->table('remember_me_tokens')->get()->getRowArray();
    check(preg_match('/^[a-f0-9]{128}$/D', $raw) === 1 && $row['token_hash'] === hash('sha256', $raw),
        'Remembered logins store only a hash of a random 64-byte token.');
    $rotated = $remember->validateRememberMeToken($raw);
    $after = $db->table('remember_me_tokens')->get()->getRowArray();
    check($rotated['account_id'] === 1 && $rotated['new_token'] !== $raw && $after['id'] === $row['id']
        && $after['token_hash'] === hash('sha256', $rotated['new_token']) && $db->table('remember_me_tokens')->countAllResults() === 1,
        'Successful rotation replaces the hash in the existing row.');
    check($remember->validateRememberMeToken($raw) === false, 'A rotated token cannot be reused.');
    check($remember->validateRememberMeToken($rotated['new_token']) !== false, 'The successor token can restore the next session.');
    foreach ([0 => 'at expiry', 3600 => 'past expiry'] as $age => $description) {
        $raw = $remember->issueRememberMeToken(1);
        $db->table('remember_me_tokens')->where('token_hash', hash('sha256', $raw))
            ->update(['expires_at' => date('Y-m-d H:i:s', time() - $age)]);
        check($remember->validateRememberMeToken($raw) === false, "A remembered login $description is rejected.");
    }
    foreach (['', 'short', str_repeat('A', 128), str_repeat('b', 128), str_repeat('a', 128) . "\n"] as $invalid) {
        if ($remember->validateRememberMeToken($invalid) !== false) throw new \RuntimeException('Invalid token accepted.');
    }
    echo "PASS: malformed and unknown remembered-login tokens are rejected\n";
    $blocked = $remember->issueRememberMeToken(1);
    $db->query('CREATE TRIGGER block_rotation BEFORE UPDATE ON remember_me_tokens BEGIN SELECT RAISE(IGNORE); END');
    check($remember->validateRememberMeToken($blocked) === false, 'A rotation that changes no row cannot authenticate.');
    $db->query('DROP TRIGGER block_rotation');
    $other = $remember->issueRememberMeToken(2);
    $remember->deleteRememberMeTokenByAccount(1);
    check($db->table('remember_me_tokens')->where('account_id', 1)->countAllResults() === 0
        && $remember->validateRememberMeToken($other) !== false, 'Account revocation removes rotated tokens and preserves other accounts.');
    $oldVersion = $remember->account_session_model->getVersion(1);
    $remember->account_session_model->invalidate(1);
    $rejected = false;
    try { $remember->issueRememberMeToken(1, $oldVersion); } catch (\RuntimeException $expected) { $rejected = true; }
    check($rejected && $db->table('remember_me_tokens')->where('account_id', 1)->countAllResults() === 0,
        'A sign-in completed before reset cannot issue a remembered login afterward.');
    $db->query("CREATE TRIGGER reset_during_issue AFTER INSERT ON remember_me_tokens BEGIN UPDATE account_session_versions SET version = '"
        . str_repeat('b', 64) . "' WHERE account_id = NEW.account_id; END");
    $rejected = false;
    try { $remember->issueRememberMeToken(1); } catch (\RuntimeException $expected) { $rejected = true; }
    check($rejected && $db->table('remember_me_tokens')->where('account_id', 1)->countAllResults() === 0,
        'A reset overlapping token insertion cannot leave a new remembered login.');
    $db->query('DROP TRIGGER reset_during_issue');
    $expired = $remember->issueRememberMeToken(3);
    $db->table('remember_me_tokens')->where('token_hash', hash('sha256', $expired))->update(['expires_at' => date('Y-m-d H:i:s')]);
    $remember->purgeRememberMeTokenExpired();
    check($db->table('remember_me_tokens')->where('account_id', 3)->countAllResults() === 0, 'Garbage collection includes the expiry boundary.');

    if (!getenv('FUSION_TEST_MYSQL_HOST')) {
        echo "SKIP: set FUSION_TEST_MYSQL_HOST to exercise concurrent MySQL rotations and revocation.\n";
        exit;
    }
    $prefix = 'remember_' . bin2hex(random_bytes(6)) . '_';
    $db = mysql($prefix);
    $db->query('CREATE TABLE ' . $prefix . 'remember_me_tokens'
        . str_replace(' (', ' (id INT AUTO_INCREMENT PRIMARY KEY, ', $columns) . ' ENGINE=InnoDB');
    $db->query('CREATE TABLE ' . $prefix . 'account_session_versions (account_id INT PRIMARY KEY, version CHAR(64) NOT NULL) ENGINE=InnoDB');
    $barrier = sys_get_temp_dir() . '/fusion-remember-race-' . bin2hex(random_bytes(8));
    mkdir($barrier, 0700);
    $workers = [];
    try {
        seed($db);
        $workers[] = startWorker($prefix, $barrier, 'rotate');
        $workers[] = startWorker($prefix, $barrier, 'rotate');
        $results = array_map('finishWorker', $workers);
        sort($results);
        check($results === ['0', '1'] && $db->table('remember_me_tokens')->countAllResults() === 1,
            'Only one overlapping MySQL rotation authenticates and stores a successor.');
        model($db)->deleteRememberMeTokenByAccount(1);
        check($db->table('remember_me_tokens')->countAllResults() === 0, 'Revocation after a concurrent rotation removes its successor.');
        foreach (glob($barrier . '/*.ready') as $file) unlink($file);
        foreach (['revoke', 'expire'] as $mode) {
            seed($db);
            $worker = startWorker($prefix, $barrier, $mode);
            $workers[] = $worker;
            $until = microtime(true) + 10;
            while (!glob($barrier . '/*.ready')) {
                if (microtime(true) > $until) throw new \RuntimeException('Worker did not read the token.');
                usleep(2000);
            }
            if ($mode === 'revoke') model($db)->deleteRememberMeTokenByAccount(1);
            else $db->table('remember_me_tokens')->update(['expires_at' => date('Y-m-d H:i:s')]);
            file_put_contents($barrier . '/release', 'release');
            $result = finishWorker($worker);
            check($result === '0', "A token that changes state before the MySQL rotation is rejected ($mode).");
            if ($mode === 'revoke') check($db->table('remember_me_tokens')->countAllResults() === 0,
                'A rotation racing account revocation cannot recreate the remembered login.');
            model($db)->purgeRememberMeTokenExpired();
            foreach (glob($barrier . '/*.ready') as $file) unlink($file);
            unlink($barrier . '/release');
        }
    } finally {
        foreach ($workers as [$process]) {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
        $db->query('DROP TABLE ' . $prefix . 'remember_me_tokens');
        $db->query('DROP TABLE ' . $prefix . 'account_session_versions');
        foreach (glob($barrier . '/*.ready') as $file) unlink($file);
        if (is_file($barrier . '/release')) unlink($barrier . '/release');
        rmdir($barrier);
    }
}
