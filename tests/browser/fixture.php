<?php

// CLI fixture management only. This file is never copied into the site's document root.
if (PHP_SAPI !== 'cli') exit(1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
[$script, $action, $configPath] = $argv;
$config = json_decode(file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
$root = $config['root'];
$site = $root . '/site';
$repo = dirname(__DIR__, 2);
if (!in_array($config['dbHost'], ['127.0.0.1', 'localhost', '::1'], true)
    || !preg_match('/^[a-f0-9]{32}$/D', $config['nonce'])) {
    throw new RuntimeException('The browser fixture requires a loopback database and a generated owner token.');
}
foreach (['cms', 'auth'] as $group) {
    if (!preg_match('/^fusion_e2e_[a-f0-9]{12}_' . $group . '$/D', $config[$group])) {
        throw new RuntimeException('Refusing an unmanaged fixture database name.');
    }
}
foreach ($config['created'] as $database) {
    if (!in_array($database, [$config['cms'], $config['auth']], true)) {
        throw new RuntimeException('Refusing to manage an unrelated database.');
    }
}
if ($action === 'cleanup' && $config['created'] === []) exit;

if ($action === 'cert') {
    $openssl = $root . '/openssl.cnf';
    file_put_contents($openssl, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=DNS:localhost,IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
    $options = ['config' => $openssl, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $key = openssl_pkey_new($options);
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key, $options + ['req_extensions' => 'v3']);
    $certificate = openssl_csr_sign($csr, null, $key, 1, $options + ['x509_extensions' => 'v3']);
    if (!$certificate || !openssl_pkey_export($key, $private, null, $options) || !openssl_x509_export($certificate, $public)) {
        throw new RuntimeException('Unable to generate the ephemeral localhost TLS certificate.');
    }
    file_put_contents($root . '/rpc-key.pem', $private);
    file_put_contents($root . '/rpc-cert.pem', $public);
    chmod($root . '/rpc-key.pem', 0600);
    exit;
}
require $repo . '/vendor/autoload.php';
if ($action === 'decode') {
    echo (new chillerlan\QRCode\QRCode())->readFromFile($argv[3])->data;
    exit;
}
$db = new mysqli($config['dbHost'], $config['dbUser'], $config['dbPassword'], '', $config['dbPort']);
$db->set_charset('utf8mb4');
$db->query("SET time_zone = '+00:00'");
function importSql(mysqli $db, string $sql): void {
    $db->multi_query($sql);
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
}
function assertOwner(mysqli $db, string $database, string $nonce): void {
    $owner = $db->query("SELECT nonce FROM `$database`.`_fusion_e2e_owner`")->fetch_assoc();
    if (!$owner || !hash_equals($nonce, $owner['nonce'])) throw new RuntimeException('Fixture ownership check failed.');
}
function resetFixture(mysqli $db, array $config): void {
    $db->select_db($config['cms']);
    foreach (['donate_mmo_transactions', 'monthly_income', 'failed_logins', 'remember_me_tokens', 'password_recovery_key', 'email_log', 'account_session_versions', 'account_totp_uses'] as $table) $db->query("DELETE FROM `$table`");
    $db->query('UPDATE account_data SET dp = 0, vp = 0');
    $db->select_db($config['auth']);
    $db->query("UPDATE account SET sha_pass_hash = UPPER(SHA1(CONCAT(username, ':BROWSERTEST123'))), totp_secret = NULL, token_key = NULL");
    foreach (glob($config['root'] . '/site/writable/cache/throttle/*.json') as $file) unlink($file);
    require_once $config['root'] . '/site/application/libraries/ConfigEditor.php';
    $editor = new ConfigEditor($config['root'] . '/site/application/config/auth.php');
    $editor->set('totp_secret_name', 'totp_secret');
    $editor->set('TOTPMasterSecret', '');
    $editor->save();
}
if ($action === 'setup') {
    // CREATE without IF NOT EXISTS prevents the installer SQL from touching an existing database.
    foreach (['cms', 'auth'] as $group) {
        $database = $config[$group];
        $db->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
        $db->select_db($database);
        $db->query('CREATE TABLE _fusion_e2e_owner (nonce CHAR(32) NOT NULL)');
        $statement = $db->prepare('INSERT INTO _fusion_e2e_owner VALUES (?)');
        $statement->bind_param('s', $config['nonce']); $statement->execute();
        $config['created'][] = $database;
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
    }
    $db->select_db($config['cms']);
    importSql($db, file_get_contents($site . '/application/modules/install/SQL/fusion_final_full.sql'));
    importSql($db, file_get_contents($site . '/application/modules/donate_mmo/sql/install.sql'));
    $db->query('DELETE FROM sideboxes');
    $db->query('DELETE FROM image_slider');
    foreach ([1 => 'E2EPLAYER', 2 => 'E2EOTHER', 3 => 'E2EOWNER'] as $id => $username) {
        $db->query("INSERT INTO account_data (id, dp, vp, nickname, language, location, avatar) VALUES ($id, 0, 0, '$username', 'english', 'Unknown', '1')");
    }
    $db->query('INSERT INTO donate_mmo_packages (price, points) VALUES (1.25, 25)');
    $db->select_db($config['auth']);
    $db->query('CREATE TABLE account (id INT PRIMARY KEY, username VARCHAR(32) UNIQUE, sha_pass_hash VARCHAR(40), email VARCHAR(100), joindate DATETIME, last_ip VARCHAR(45), last_login DATETIME, expansion INT DEFAULT 0, totp_secret VARBINARY(128), token_key VARCHAR(128))');
    $db->query('CREATE TABLE account_access (AccountId INT, SecurityLevel INT, RealmID INT DEFAULT -1)');
    $db->query('CREATE TABLE account_banned (id INT, banreason VARCHAR(255), active INT, bandate INT, unbandate INT, bannedby VARCHAR(50))');
    $db->query('CREATE TABLE ip_banned (ip VARCHAR(45), banreason VARCHAR(255), bandate INT, unbandate INT, bannedby VARCHAR(50))');
    foreach ([1 => 'E2EPLAYER', 2 => 'E2EOTHER', 3 => 'E2EOWNER'] as $id => $username) {
        $hash = strtoupper(sha1($username . ':BROWSERTEST123'));
        $email = strtolower($username) . '@example.invalid';
        $db->query("INSERT INTO account (id, username, sha_pass_hash, email, joindate, last_ip, last_login) VALUES ($id, '$username', '$hash', '$email', UTC_TIMESTAMP(), '127.0.0.1', UTC_TIMESTAMP())");
    }
    $connections = [];
    foreach (['cms', 'account'] as $group) {
        $connections[$group] = ['hostname' => $config['dbHost'], 'username' => $config['dbUser'], 'password' => $config['dbPassword'],
            'database' => $config[$group === 'cms' ? 'cms' : 'auth'], 'DBDriver' => 'MySQLi', 'DBPrefix' => '', 'pConnect' => false,
            'DBDebug' => true, 'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_general_ci', 'port' => $config['dbPort']];
    }
    file_put_contents($site . '/application/config/Database.php', "<?php\nnamespace App\\Config;\nclass Database extends \\CodeIgniter\\Database\\Config {\npublic string \$defaultGroup = 'cms';\npublic array \$cms = " . var_export($connections['cms'], true) . ";\npublic array \$account = " . var_export($connections['account'], true) . ";\n}\n");
    require $site . '/application/libraries/ConfigEditor.php';
    copy($site . '/application/config/fusion.php.dist', $site . '/application/config/fusion.php');
    foreach ([
        'fusion' => ['title' => 'FusionCMS Browser E2E', 'server_name' => 'Browser Fixture', 'cache' => false, 'detect_language' => false, 'slider' => false, 'security_code' => 'BrowserFixtureOnly', 'has_smtp' => 1],
        'auth' => ['account_encryption' => 'SPH', 'rbac' => false, 'battle_net' => false, 'totp_secret' => true, 'totp_secret_name' => 'totp_secret', 'TOTPMasterSecret' => ''],
        'captcha' => ['use_captcha' => false, 'captcha_attemps' => 100],
        'config' => ['encryption_key' => bin2hex(random_bytes(32))],
        'smtp' => ['smtp_protocol' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => $config['smtpPort'],
            'smtp_user' => '', 'smtp_pass' => '', 'smtp_crypto' => '', 'smtp_sender' => 'noreply@example.invalid'],
    ] as $file => $values) {
        $editor = new ConfigEditor($site . '/application/config/' . $file . '.php');
        foreach ($values as $key => $value) $editor->set($key, $value);
        $editor->save();
    }
    $payment = json_decode(file_get_contents($repo . '/tests/fixtures/solana_devnet_payments.json'), true)['payments'][0]['invoice'];
    $editor = new ConfigEditor($site . '/application/modules/donate_mmo/config/donate_mmo.php');
    foreach (['rpc_url' => $config['rpcUrl'], 'merchant_wallet' => $payment['merchant_wallet'], 'mmo_coin_mint' => $payment['token_mint'], 'exchange_rate' => '0.8'] as $key => $value) $editor->set($key, $value);
    $editor->save();
    file_put_contents($site . '/application/config/owner.php', "<?php \$config['owner'] = 'E2EOWNER';\n");
    foreach (['install', 'cache', 'logs', 'session'] as $folder) {
        if (!is_dir($site . '/writable/' . $folder)) mkdir($site . '/writable/' . $folder, 0777, true);
    }
    file_put_contents($site . '/writable/install/.lock', 'Isolated browser fixture');
    echo "Disposable CMS and auth schemas initialized.\n";
    exit;
}
foreach ($config['created'] as $database) assertOwner($db, $database, $config['nonce']);
if ($action === 'cleanup') {
    foreach ($config['created'] as $database) $db->query("DROP DATABASE `$database`");
} elseif ($action === 'reset') {
    resetFixture($db, $config);
} elseif ($action === 'age-recovery') {
    if (!preg_match('/^[a-f0-9]{128}$/D', $argv[3]) || !ctype_digit($argv[4]) || (int) $argv[4] > 86400) {
        throw new RuntimeException('Invalid reset-token age fixture.');
    }
    $db->select_db($config['cms']);
    $issued = time() - (int) $argv[4];
    $statement = $db->prepare('UPDATE password_recovery_key SET time = ? WHERE recoverykey = ?');
    $statement->bind_param('is', $issued, $argv[3]); $statement->execute();
} elseif ($action === 'expire-remember') {
    if (!preg_match('/^[a-f0-9]{128}$/D', $argv[3])) throw new RuntimeException('Invalid remembered-login fixture token.');
    $db->select_db($config['cms']);
    $expires = gmdate('Y-m-d H:i:s');
    $hash = hash('sha256', $argv[3]);
    $statement = $db->prepare('UPDATE remember_me_tokens SET expires_at = ? WHERE token_hash = ?');
    $statement->bind_param('ss', $expires, $hash); $statement->execute();
    if ($statement->affected_rows !== 1) throw new RuntimeException('Expected one remembered login to expire.');
} elseif ($action === 'remembered') {
    $db->select_db($config['cms']);
    echo json_encode($db->query('SELECT * FROM remember_me_tokens ORDER BY id')->fetch_all(MYSQLI_ASSOC), JSON_THROW_ON_ERROR);
} elseif ($action === 'totp-mode' || $action === 'seed-totp') {
    $mode = $argv[3] ?? 'binary';
    if (!in_array($mode, ['binary', 'base32', 'encrypted'], true)) throw new RuntimeException('Invalid TOTP fixture mode.');
    require_once $site . '/application/libraries/ConfigEditor.php';
    $editor = new ConfigEditor($site . '/application/config/auth.php');
    $editor->set('totp_secret_name', $mode === 'base32' ? 'token_key' : 'totp_secret');
    $editor->set('TOTPMasterSecret', $mode === 'encrypted' ? '000102030405060708090A0B0C0D0E0F' : '');
    $editor->save();
    if ($action === 'seed-totp') {
        $accountId = (int) ($argv[4] ?? 1);
        if (!in_array($accountId, [1, 2], true)) throw new RuntimeException('Invalid TOTP fixture account.');
        $base32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $secret = $mode === 'base32' ? $base32 : '12345678901234567890';
        if ($mode === 'encrypted') {
            $iv = random_bytes(12);
            $secret = openssl_encrypt($secret, 'aes-128-gcm', hex2bin('0f0e0d0c0b0a09080706050403020100'), OPENSSL_RAW_DATA, $iv, $tag, '', 12) . $iv . $tag;
        }
        $column = $mode === 'base32' ? 'token_key' : 'totp_secret';
        $db->select_db($config['auth']);
        $statement = $db->prepare("UPDATE account SET `$column` = ? WHERE id = $accountId");
        $statement->bind_param('s', $secret); $statement->execute();
        echo $base32;
    }
} elseif ($action === 'expire-totp-setup') {
    if (!preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $argv[3] ?? '')) throw new RuntimeException('Invalid fixture session ID.');
    $db->select_db($config['cms']);
    $sessionId = 'fusion_session:' . $argv[3];
    $statement = $db->prepare('SELECT data FROM ci_sessions WHERE id = ?');
    $statement->bind_param('s', $sessionId); $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    if (!$row) throw new RuntimeException('No fixture session to expire.');
    session_start(['use_cookies' => 0, 'cache_limiter' => '', 'save_path' => $root]);
    if (!session_decode($row['data']) || !isset($_SESSION['totp_enrollment'])) throw new RuntimeException('No pending fixture enrollment.');
    $_SESSION['totp_enrollment']['expires'] = time() - 1;
    $data = session_encode();
    session_destroy();
    $statement = $db->prepare('UPDATE ci_sessions SET data = ? WHERE id = ?');
    $statement->bind_param('ss', $data, $sessionId); $statement->execute();
} elseif ($action === 'totp-state') {
    $db->select_db($config['auth']);
    $row = $db->query('SELECT HEX(totp_secret) AS binary_secret, token_key FROM account WHERE id = 1')->fetch_assoc();
    $db->select_db($config['cms']);
    $row['claims'] = $db->query('SELECT * FROM account_totp_uses ORDER BY account_id, secret_hash')->fetch_all(MYSQLI_ASSOC);
    echo json_encode($row, JSON_THROW_ON_ERROR);
} elseif ($action === 'snapshot') {
    $db->select_db($config['cms']);
    echo json_encode([
        'accounts' => $db->query('SELECT id, dp FROM account_data ORDER BY id')->fetch_all(MYSQLI_ASSOC),
        'invoices' => $db->query('SELECT * FROM donate_mmo_transactions ORDER BY id')->fetch_all(MYSQLI_ASSOC),
        'income' => $db->query('SELECT * FROM monthly_income')->fetch_all(MYSQLI_ASSOC),
    ], JSON_THROW_ON_ERROR);
} else {
    throw new RuntimeException('Unknown browser fixture command.');
}
