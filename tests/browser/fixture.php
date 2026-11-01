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
    foreach (['donate_mmo_transactions', 'monthly_income', 'failed_logins', 'remember_me_tokens'] as $table) $db->query("DELETE FROM `$table`");
    $db->query('UPDATE account_data SET dp = 0, vp = 0');
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
    $db->query('CREATE TABLE account (id INT PRIMARY KEY, username VARCHAR(32) UNIQUE, sha_pass_hash VARCHAR(40), email VARCHAR(100), joindate DATETIME, last_ip VARCHAR(45), last_login DATETIME, expansion INT DEFAULT 0)');
    $db->query('CREATE TABLE account_access (AccountId INT, SecurityLevel INT, RealmID INT DEFAULT -1)');
    $db->query('CREATE TABLE account_banned (id INT, banreason VARCHAR(255), active INT, bandate INT, unbandate INT, bannedby VARCHAR(50))');
    $db->query('CREATE TABLE ip_banned (ip VARCHAR(45), banreason VARCHAR(255), bandate INT, unbandate INT, bannedby VARCHAR(50))');
    foreach ([1 => 'E2EPLAYER', 2 => 'E2EOTHER', 3 => 'E2EOWNER'] as $id => $username) {
        $hash = strtoupper(sha1($username . ':BROWSERTEST123'));
        $db->query("INSERT INTO account (id, username, sha_pass_hash, email, joindate, last_ip, last_login) VALUES ($id, '$username', '$hash', 'browser@example.invalid', UTC_TIMESTAMP(), '127.0.0.1', UTC_TIMESTAMP())");
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
        'fusion' => ['title' => 'FusionCMS Browser E2E', 'server_name' => 'Browser Fixture', 'cache' => false, 'detect_language' => false, 'slider' => false, 'security_code' => 'BrowserFixtureOnly'],
        'auth' => ['account_encryption' => 'SPH', 'rbac' => false, 'battle_net' => false, 'totp_secret' => false],
        'captcha' => ['use_captcha' => false, 'captcha_attemps' => 100],
        'config' => ['encryption_key' => bin2hex(random_bytes(32))],
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
