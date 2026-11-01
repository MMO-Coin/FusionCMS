<?php

namespace CodeIgniter\Events {
    class Events
    {
        public static function trigger($name, ...$arguments) {}
    }
}

namespace {
    define('ENVIRONMENT', 'testing');
    define('CI_DEBUG', false);
    function log_message(...$arguments) {}
    function config(...$arguments) { return (object) ['limitZeroAsAll' => true]; }
    class CI_Model { public $db; }

    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../application/modules/donate_mmo/libraries/SolanaPaymentVerifier.php';
    require_once __DIR__ . '/../application/modules/donate_mmo/models/Mmocoin_model.php';

    function check(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
        echo "PASS: $message\n";
    }

    function mysqlConnection()
    {
        $connection = new \CodeIgniter\Database\MySQLi\Connection([
            'hostname' => getenv('FUSION_TEST_MYSQL_HOST'), 'username' => 'root',
            'password' => getenv('FUSION_TEST_MYSQL_PASSWORD'), 'database' => 'fusion_test',
            'port' => (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306),
            'DBDebug' => true, 'DBPrefix' => '', 'charset' => 'utf8mb4',
        ]);
        $connection->transException(true);
        return $connection;
    }

    if (($argv[1] ?? '') === 'complete-race') {
        $worker = (new \ReflectionClass(\Mmocoin_model::class))->newInstanceWithoutConstructor();
        $worker->db = mysqlConnection();
        echo $worker->completeTransaction('race', 1, 'sig-race') ? '1' : '0';
        exit;
    }

    $invoice = [
        'reference' => str_repeat('3', 32), 'merchant_wallet' => str_repeat('4', 32),
        'token_mint' => str_repeat('5', 32), 'mmo_amount' => '1.250000000',
        'created_at' => '2026-01-01 00:00:00', 'expires_at' => '2026-01-01 01:00:00',
    ];
    $signature = str_repeat('2', 88);
    $tx = [
        'blockTime' => strtotime('2026-01-01 00:10:00 UTC'),
        'transaction' => ['signatures' => [$signature], 'message' => ['accountKeys' => [
            ['pubkey' => $invoice['reference'], 'signer' => false, 'writable' => false],
        ]]],
        'meta' => ['err' => null, 'preTokenBalances' => [], 'postTokenBalances' => [[
            'accountIndex' => 2, 'mint' => $invoice['token_mint'], 'owner' => $invoice['merchant_wallet'],
            'programId' => SolanaPaymentVerifier::TOKEN_PROGRAM,
            'uiTokenAmount' => ['amount' => '1250000000', 'decimals' => 9],
        ]]],
    ];
    check(SolanaPaymentVerifier::verifies($tx, $invoice, $signature), 'A finalized successful payment with the required net token credit is accepted');
    $cases = [
        'Failed transaction' => static function (&$candidate) { $candidate['meta']['err'] = ['InstructionError' => [0, 'failed']]; },
        'Missing execution result' => static function (&$candidate) { unset($candidate['meta']['err']); },
        'Underpayment' => static function (&$candidate) { $candidate['meta']['postTokenBalances'][0]['uiTokenAmount']['amount'] = '1249999999'; },
        'Wrong merchant' => static function (&$candidate) { $candidate['meta']['postTokenBalances'][0]['owner'] = 'attacker'; },
        'Wrong mint' => static function (&$candidate) { $candidate['meta']['postTokenBalances'][0]['mint'] = 'other-token'; },
        'Wrong token program' => static function (&$candidate) { $candidate['meta']['postTokenBalances'][0]['programId'] = 'attacker-program'; },
        'Missing reference' => static function (&$candidate) { $candidate['transaction']['message']['accountKeys'] = []; },
        'Writable reference' => static function (&$candidate) { $candidate['transaction']['message']['accountKeys'][0]['writable'] = true; },
        'Signer reference' => static function (&$candidate) { $candidate['transaction']['message']['accountKeys'][0]['signer'] = true; },
        'Wrong signature' => static function (&$candidate) { $candidate['transaction']['signatures'][0] = 'other'; },
        'Payment before invoice' => static function (&$candidate) { $candidate['blockTime'] = strtotime('2025-12-31 23:59:59 UTC'); },
        'Payment at expiry' => static function (&$candidate) { $candidate['blockTime'] = strtotime('2026-01-01 01:00:00 UTC'); },
        'Missing timestamp' => static function (&$candidate) { $candidate['blockTime'] = null; },
        'Non-integer token units' => static function (&$candidate) { $candidate['meta']['postTokenBalances'][0]['uiTokenAmount']['amount'] = '1.25e9'; },
        'Unchanged existing balance' => static function (&$candidate) { $candidate['meta']['preTokenBalances'] = $candidate['meta']['postTokenBalances']; },
    ];
    foreach ($cases as $name => $mutate) {
        $candidate = $tx;
        $mutate($candidate);
        check(!SolanaPaymentVerifier::verifies($candidate, $invoice, $signature), "$name is rejected");
    }
    check(SolanaPaymentVerifier::amount('100.000000000') === '100', 'Whole amounts preserve their trailing whole-number zeros');
    check(SolanaPaymentVerifier::income('1.25', '2') === 2, 'Income is calculated with integer arithmetic');
    $large = $invoice;
    $large['mmo_amount'] = '9007199.254740993';
    $candidate = $tx;
    $candidate['meta']['postTokenBalances'][0]['uiTokenAmount']['amount'] = '9007199254740993';
    check(SolanaPaymentVerifier::verifies($candidate, $large, $signature), 'Token units above floating-point precision are compared exactly');
    $candidate['meta']['postTokenBalances'][0]['uiTokenAmount']['amount'] = '9007199254740992';
    check(!SolanaPaymentVerifier::verifies($candidate, $large, $signature), 'A one-unit underpayment above floating-point precision is rejected');
    foreach (['0', '-1', '1e9', '1.0000000001', 'NaN'] as $amount) {
        try { SolanaPaymentVerifier::amount($amount); throw new \RuntimeException("Invalid amount $amount was accepted"); }
        catch (\InvalidArgumentException $expected) { echo "PASS: invalid amount $amount is rejected\n"; }
    }
    check(SolanaPaymentVerifier::validAddress('11111111111111111111111111111111'), 'A 32-byte Base58 address is accepted');
    check(!SolanaPaymentVerifier::validAddress(str_repeat('1', 33)), 'Wrong-length Base58 bytes are rejected');

    $mysql = getenv('FUSION_TEST_MYSQL_HOST');
    $db = $mysql ? mysqlConnection() : new \CodeIgniter\Database\SQLite3\Connection(['database' => ':memory:', 'DBDebug' => true, 'DBPrefix' => '']);
    $db->transException(true);

    if ($mysql) {
        function runSql($db, string $path): void
        {
            $sql = preg_replace('/^--.*$/m', '', file_get_contents($path));
            foreach (explode(';', $sql) as $statement) {
                if (trim($statement) !== '') $db->query(trim($statement));
            }
        }
        $db->query('DROP TABLE IF EXISTS donate_mmo_transactions');
        $db->query('DROP TABLE IF EXISTS donate_mmo_packages');
        $db->query('CREATE TABLE donate_mmo_packages (id INT PRIMARY KEY, price DOUBLE NOT NULL, points INT NOT NULL) ENGINE=InnoDB');
        $db->query("CREATE TABLE donate_mmo_transactions (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT, mmo_amount DECIMAL(20,9), dp_amount INT, reference VARCHAR(64) UNIQUE, status VARCHAR(16), transaction_signature VARCHAR(128), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
        $db->table('donate_mmo_transactions')->insert(['user_id' => 1, 'reference' => 'legacy', 'status' => 'pending']);
        $upgrade = __DIR__ . '/../application/modules/donate_mmo/sql/upgrade_1_1.sql';
        runSql($db, $upgrade);
        runSql($db, $upgrade);
        check($db->table('donate_mmo_transactions')->where('reference', 'legacy')->get()->getRowArray()['status'] === 'failed', 'Repeated module upgrade safely invalidates legacy pending invoices');
        $db->query('DROP TABLE donate_mmo_transactions');
        $db->query('DROP TABLE donate_mmo_packages');
        runSql($db, __DIR__ . '/../application/modules/donate_mmo/sql/install.sql');
        runSql($db, $upgrade);
        check($db->table('donate_mmo_transactions')->countAllResults() === 0, 'Fresh module installation and upgrade are compatible');
    } else {
        $db->query('CREATE TABLE donate_mmo_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, mmo_amount TEXT, dp_amount INTEGER, reference TEXT UNIQUE, status TEXT, transaction_signature TEXT UNIQUE, merchant_wallet TEXT, token_mint TEXT, income_amount INTEGER, created_at TEXT, expires_at TEXT)');
        echo "NOTE: MySQL-specific migration tests run in CI with the MySQL service.\n";
    }
    $db->query('DROP TABLE IF EXISTS account_data');
    $db->query('DROP TABLE IF EXISTS monthly_income');
    $db->query('CREATE TABLE account_data (id INTEGER PRIMARY KEY, dp INTEGER NOT NULL DEFAULT 0)');
    $db->query('CREATE TABLE monthly_income (month VARCHAR(7) PRIMARY KEY, amount INTEGER NOT NULL)');
    $db->table('account_data')->insert(['id' => 1, 'dp' => 0]);
    $model = (new \ReflectionClass(\Mmocoin_model::class))->newInstanceWithoutConstructor();
    $model->db = $db;
    $model->createTransaction(1, '1.25', 10, 'first', 'merchant', 'mint', 2);
    check($model->getTransaction('first', 2) === false, 'Another user cannot read an invoice');
    check(!$model->completeTransaction('first', 2, 'sig-first'), 'Another user cannot complete an invoice');
    check($model->completeTransaction('first', 1, 'sig-first'), 'Invoice, points and monthly income commit together');
    check(!$model->completeTransaction('first', 1, 'sig-first'), 'Repeated invoice completion is rejected');
    check((int) $db->table('account_data')->where('id', 1)->get()->getRowArray()['dp'] === 10, 'Repeated completion grants points once');
    check((int) $db->table('monthly_income')->get()->getRowArray()['amount'] === 2, 'Repeated completion counts income once');
    $model->createTransaction(1, '1.25', 10, 'expired', 'merchant', 'mint', 2);
    $db->table('donate_mmo_transactions')->where('reference', 'expired')->update(['expires_at' => '2000-01-01 00:00:00']);
    check(!$model->completeTransaction('expired', 1, 'sig-expired'), 'Expired invoice cannot receive points');
    $model->createTransaction(2, '1.25', 10, 'missing-account', 'merchant', 'mint', 2);
    check(!$model->completeTransaction('missing-account', 2, 'sig-missing'), 'Missing account rolls back completion');
    check($model->getTransaction('missing-account', 2)['status'] === 'pending', 'Failed credit leaves the invoice pending');
    $model->createTransaction(1, '1.25', 10, 'reused', 'merchant', 'mint', 2);
    check(!$model->completeTransaction('reused', 1, 'sig-first'), 'One transaction signature cannot pay two invoices');
    check($model->getTransaction('reused', 1)['status'] === 'pending', 'Signature replay rolls back the second invoice');
    check((int) $db->table('account_data')->where('id', 1)->get()->getRowArray()['dp'] === 10, 'Rejected payments leave donation points unchanged');
    if ($mysql) {
        $db = mysqlConnection();
        $model->db = $db;
        $model->createTransaction(1, '1.25', 7, 'race', 'merchant', 'mint', 3);
        $db->transBegin();
        $db->query("SELECT id FROM donate_mmo_transactions WHERE reference = 'race' FOR UPDATE");
        $workers = [];
        for ($i = 0; $i < 3; $i++) {
            $process = proc_open([PHP_BINARY, __FILE__, 'complete-race'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new \RuntimeException('Unable to start concurrent test worker');
            $workers[] = [$process, $pipes];
        }
        usleep(300000);
        $db->transCommit();
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $results[] = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) throw new \RuntimeException('Concurrent worker failed: ' . $error);
        }
        sort($results);
        check($results === ['0', '0', '1'], 'Three concurrent payment completions have exactly one winner');
        check((int) $db->table('account_data')->where('id', 1)->get()->getRowArray()['dp'] === 17, 'Concurrent completion credits donation points once');
        check((int) $db->table('monthly_income')->get()->getRowArray()['amount'] === 5, 'Concurrent completion records income once');
    }
    $pointsBeforeFailure = (int) $db->table('account_data')->where('id', 1)->get()->getRowArray()['dp'];
    $model->createTransaction(1, '1.25', 10, 'income-failure', 'merchant', 'mint', 2);
    $db->query('DROP TABLE monthly_income');
    check(!$model->completeTransaction('income-failure', 1, 'sig-income-failure'), 'Income storage failure rejects payment completion');
    check($model->getTransaction('income-failure', 1)['status'] === 'pending', 'Income storage failure rolls back invoice status');
    check((int) $db->table('account_data')->where('id', 1)->get()->getRowArray()['dp'] === $pointsBeforeFailure, 'Income storage failure rolls back donation points');
}
