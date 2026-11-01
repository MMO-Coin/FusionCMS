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
    require __DIR__ . '/../application/modules/auth/models/Password_recovery_model.php';

    function check(bool $condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
        echo "PASS: $message\n";
    }
    function model($db): \Password_recovery_model {
        $model = (new \ReflectionClass(\Password_recovery_model::class))->newInstanceWithoutConstructor();
        $model->db = $db;
        return $model;
    }
    function mysql(string $prefix) {
        if (!preg_match('/^recovery_[a-f0-9]{12}_$/D', $prefix)) throw new \RuntimeException('Invalid test table prefix.');
        return new \CodeIgniter\Database\MySQLi\Connection([
            'hostname' => getenv('FUSION_TEST_MYSQL_HOST'), 'username' => 'root',
            'password' => getenv('FUSION_TEST_MYSQL_PASSWORD'), 'database' => 'fusion_test',
            'port' => (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306), 'DBPrefix' => $prefix,
            'DBDebug' => true, 'charset' => 'utf8mb4',
        ]);
    }
    class OverlappingRecovery extends \Password_recovery_model {
        public string $barrier;
        public function get_token($token) {
            $record = parent::get_token($token);
            if (!$record) return false;
            file_put_contents($this->barrier . '/' . getmypid() . '.ready', 'ready');
            $until = microtime(true) + 10;
            while (count(glob($this->barrier . '/*.ready')) < 2) {
                if (microtime(true) > $until) throw new \RuntimeException('Token claim barrier timed out.');
                usleep(2000);
            }
            return $record;
        }
    }
    if (($argv[1] ?? '') === 'claim') {
        $worker = (new \ReflectionClass(OverlappingRecovery::class))->newInstanceWithoutConstructor();
        $worker->db = mysql($argv[2]);
        $worker->barrier = $argv[3];
        echo $worker->consume_token(str_repeat('a', 128)) ? '1' : '0';
        exit;
    }

    $schema = ' (recoverykey VARCHAR(255) NOT NULL, username VARCHAR(255) NOT NULL, email VARCHAR(255), ip VARCHAR(50) NOT NULL, time INTEGER NOT NULL)';
    $db = new \CodeIgniter\Database\SQLite3\Connection(['database' => ':memory:', 'DBDebug' => true]);
    $db->query('CREATE TABLE password_recovery_key' . $schema);
    $recovery = model($db);
    $token = str_repeat('a', 128);
    $recovery->insert_token($token, 'PLAYER', 'player@example.invalid', '127.0.0.1');
    check($recovery->get_token($token)['username'] === 'PLAYER', 'A fresh reset token identifies its account.');
    check($recovery->consume_token($token)['username'] === 'PLAYER', 'A fresh reset token can be claimed once.');
    check($recovery->consume_token($token) === false, 'A consumed reset token cannot be reused.');
    foreach ([3600 => 'at expiry', 7200 => 'past expiry', -3600 => 'issued in the future'] as $age => $description) {
        $recovery->insert_token($token, 'PLAYER', 'player@example.invalid', '127.0.0.1');
        $db->table('password_recovery_key')->where('recoverykey', $token)->update(['time' => time() - $age]);
        check($recovery->get_token($token) === false && $recovery->consume_token($token) === false, "A reset token $description cannot be used.");
        $db->table('password_recovery_key')->where('recoverykey', $token)->delete();
    }
    check($recovery->consume_token(str_repeat('b', 128)) === false, 'An unknown reset token is rejected.');
    foreach (['', 'short', str_repeat('A', 128), [$token]] as $invalid) {
        if ($recovery->consume_token($invalid) !== false) throw new \RuntimeException('Malformed token accepted.');
    }
    echo "PASS: malformed reset token values are rejected\n";

    if (!getenv('FUSION_TEST_MYSQL_HOST')) {
        echo "SKIP: set FUSION_TEST_MYSQL_HOST to exercise concurrent MySQL token claims.\n";
        exit;
    }
    $prefix = 'recovery_' . bin2hex(random_bytes(6)) . '_';
    $db = mysql($prefix);
    $db->query('CREATE TABLE ' . $prefix . 'password_recovery_key' . $schema . ' ENGINE=InnoDB');
    $barrier = sys_get_temp_dir() . '/fusion-reset-race-' . bin2hex(random_bytes(8));
    mkdir($barrier, 0700);
    $workers = [];
    try {
        model($db)->insert_token($token, 'PLAYER', 'player@example.invalid', '127.0.0.1');
        for ($index = 0; $index < 2; $index++) {
            $process = proc_open([PHP_BINARY, __FILE__, 'claim', $prefix, $barrier], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new \RuntimeException('Cannot start the claim worker.');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $results[] = trim(stream_get_contents($pipes[1]));
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0) throw new \RuntimeException('Claim worker failed: ' . $error);
        }
        sort($results);
        check($results === ['0', '1'], 'Only one overlapping MySQL reset-token claim succeeds.');
    } finally {
        foreach ($workers as [$process]) {
            if (is_resource($process)) {proc_terminate($process); proc_close($process);}
        }
        $db->query('DROP TABLE ' . $prefix . 'password_recovery_key');
        foreach (glob($barrier . '/*.ready') as $file) unlink($file);
        rmdir($barrier);
    }
}
