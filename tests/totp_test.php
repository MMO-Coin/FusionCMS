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
class CI_Model { public $db; public $config; }
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/models/Totp_model.php';
require __DIR__ . '/../application/libraries/GoogleAuthenticator.php';
require __DIR__ . '/../application/models/External_account_model.php';
function table($name) { global $accountTable; return $accountTable; }
function column($table, $name) { return $name; }
class FixtureAccount extends External_account_model {
    public $fixtureDb;
    public function getConnection(): CodeIgniter\Database\BaseConnection { return $this->fixtureDb; }
}
$settings = ['totp_secret_name' => 'token_key', 'TOTPMasterSecret' => ''];
$ci = (object) ['config' => new class {
    public function item($key) { global $settings; return $settings[$key] ?? null; }
}];
function &get_instance() { global $ci; return $ci; }
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
function model($db): Totp_model { $model = new Totp_model(); $model->db = $db; return $model; }
function mysql(string $prefix) {
    if (!preg_match('/^totp_[a-f0-9]{12}_$/D', $prefix)
        || !in_array(getenv('FUSION_TEST_MYSQL_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('TOTP races require an isolated loopback database.');
    }
    return new CodeIgniter\Database\MySQLi\Connection([
        'hostname' => getenv('FUSION_TEST_MYSQL_HOST'), 'username' => 'root',
        'password' => getenv('FUSION_TEST_MYSQL_PASSWORD'), 'database' => 'fusion_test',
        'port' => (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306), 'DBPrefix' => $prefix,
        'DBDebug' => true, 'charset' => 'utf8mb4',
    ]);
}
if (($argv[1] ?? '') === 'worker') {
    $claims = model(mysql($argv[2]));
    $barrier = $argv[3];
    file_put_contents($barrier . '/' . getmypid() . '.ready', 'ready');
    $until = microtime(true) + 10;
    while (count(glob($barrier . '/*.ready')) < 2) {
        if (microtime(true) > $until) throw new RuntimeException('TOTP race barrier timed out.');
        usleep(2000);
    }
    echo $claims->claim(1, 'race-key', 100) ? '1' : '0';
    exit;
}
$otp = new GoogleAuthenticator();
$base32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
    check($otp->matchingTimeStep($base32, $code, 0, intdiv($time, 30)) === intdiv($time, 30), 'RFC 6238 SHA-1 vector at ' . $time);
}
$step = 1000;
foreach ([-1, 0, 1] as $offset) check($otp->matchingTimeStep($base32, $otp->getCode($base32, $step + $offset), 1, $step) === $step + $offset, 'Accept only the configured adjacent time steps: ' . $offset);
check(!$otp->verifyCode($base32, $otp->getCode($base32, $step - 2), 1, $step), 'Expired codes outside the window are rejected.');
foreach (['', '12345', '1234567', '12a456', ' 287082', '287082\n'] as $code) check(!$otp->verifyCode($base32, $code, 0, 1), 'Malformed codes are rejected: ' . json_encode($code));
check(!$otp->verifyCode('', '000000') && !$otp->verifyCode('invalid-secret', '123456'), 'Empty and malformed secrets fail closed.');
check(strlen($otp->createSecret()) === 32, 'New enrollment uses a 160-bit random secret.');
$settings['totp_secret_name'] = 'totp_secret';
$raw = $otp->createTotpAes($base32);
check($raw === '12345678901234567890' && $otp->verifyCode($raw, '287082', 0, 1), 'Binary TrinityCore secrets use the same TOTP vectors.');
$settings['TOTPMasterSecret'] = '000102030405060708090A0B0C0D0E0F';
$encrypted = $otp->createTotpAes($base32);
check(strlen($encrypted) === 44 && $otp->storedSecretKey($encrypted) === $raw && $otp->verifyCode($encrypted, '287082', 0, 1), 'TrinityCore AES-128-GCM envelopes retain their IV and authentication tag.');
check(openssl_decrypt(substr($encrypted, 0, -24), 'aes-128-gcm', hex2bin('0f0e0d0c0b0a09080706050403020100'), OPENSSL_RAW_DATA, substr($encrypted, -24, 12), substr($encrypted, -12)) === $raw, 'Encrypted secrets follow TrinityCore key byte order and envelope layout.');
$encrypted[0] = chr(ord($encrypted[0]) ^ 1);
check(!$otp->verifyCode($encrypted, '287082', 0, 1), 'Tampered encrypted secrets fail closed.');
$settings['TOTPMasterSecret'] = '';

$db = new CodeIgniter\Database\SQLite3\Connection(['database' => ':memory:', 'DBDebug' => true]);
$db->query('CREATE TABLE account_totp_uses (account_id INT, secret_hash CHAR(64), time_step BIGINT, PRIMARY KEY(account_id, secret_hash))');
$claims = model($db);
check($claims->claim(1, $raw, 100) && !$claims->claim(1, $raw, 100), 'A verified code can only be claimed once.');
check($claims->claim(1, $raw, 101) && !$claims->claim(1, $raw, 99), 'A later success also rejects older codes still inside the drift window.');
check($claims->claim(2, $raw, 100) && $claims->claim(1, 'different-key', 100), 'Claims are isolated by account and authenticator key.');
check(!$claims->claim(0, $raw, 100) && !$claims->claim(1, '', 100) && !$claims->claim(1, $raw, -1), 'Invalid claim identities fail closed.');
$row = $db->table('account_totp_uses')->where('account_id', 1)->where('secret_hash', hash('sha256', $raw))->get()->getRowArray();
check($row['secret_hash'] === hash('sha256', $raw) && count($row) === 3, 'Replay records store a key fingerprint and time step, without codes or secrets.');

if (!getenv('FUSION_TEST_MYSQL_HOST')) { echo "SKIP: set FUSION_TEST_MYSQL_HOST for concurrent claims and migration checks.\n"; exit; }
$prefix = 'totp_' . bin2hex(random_bytes(6)) . '_';
$db = mysql($prefix);
$migration = str_replace('`account_totp_uses`', '`' . $prefix . 'account_totp_uses`', file_get_contents(__DIR__ . '/../application/modules/install/SQL/updates/2026_11_02_00_account_totp_uses.sql'));
$db->query($migration);
$accountTable = $prefix . 'account';
$db->query('CREATE TABLE ' . $accountTable . ' (id INT PRIMARY KEY, totp_secret VARBINARY(128), token_key VARCHAR(128), sha_pass_hash VARCHAR(40), verifier VARCHAR(64)) ENGINE=InnoDB');
$barrier = sys_get_temp_dir() . '/fusion-totp-race-' . bin2hex(random_bytes(8));
mkdir($barrier, 0700);
$workers = [];
try {
    $account = (new ReflectionClass(FixtureAccount::class))->newInstanceWithoutConstructor();
    $account->fixtureDb = $db; $account->config = $ci->config;
    $settings['account_encryption'] = 'SPH';
    $db->table('account')->insert(['id' => 1, 'sha_pass_hash' => 'PASSWORD', 'verifier' => 'SRPVERIFIER']);
    $binary = "\x00\xff\x27\x5c" . random_bytes(16);
    check(!$account->changeTotpSecret(1, '', $binary, 'OLDPASSWORD'), 'A password changed after verification rejects the secret update.');
    check($account->changeTotpSecret(1, '', $binary, 'PASSWORD'), 'Authenticator enrollment atomically changes the expected account state.');
    check(!$account->changeTotpSecret(1, '', $raw, 'PASSWORD'), 'A stale enrollment cannot overwrite another browser authenticator.');
    check(!$account->changeTotpSecret(1, str_repeat('x', 20), null, 'PASSWORD') && $account->changeTotpSecret(1, $binary, null, 'PASSWORD'), 'Disabling compares exact binary bytes before clearing the secret.');
    $settings['account_encryption'] = 'SRP6';
    check($account->changeTotpSecret(1, '', $raw, 'SRPVERIFIER'), 'Secret updates also support mapped SRP verifier credentials.');
    for ($i = 0; $i < 2; ++$i) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $prefix, $barrier], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start TOTP worker.');
        fclose($pipes[0]); $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $results[] = trim(stream_get_contents($pipes[1])); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('TOTP worker failed: ' . $errors);
    }
    sort($results);
    check($results === ['0', '1'] && $db->table('account_totp_uses')->countAllResults() === 1, 'Exactly one simultaneous MySQL claim succeeds, including the first use of a key.');
    $db->query($migration);
    check(!model($db)->claim(1, 'race-key', 100), 'Reapplying the migration preserves replay rejection.');
} finally {
    foreach ($workers as [$process]) if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    $db->query('DROP TABLE ' . $prefix . 'account_totp_uses');
    $db->query('DROP TABLE ' . $accountTable);
    foreach (glob($barrier . '/*.ready') as $file) unlink($file);
    rmdir($barrier);
}
}
