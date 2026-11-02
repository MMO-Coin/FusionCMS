// Runs only against a unique, disposable Compose project. Never uses docker/.env.
const {execFileSync} = require('node:child_process');
const crypto = require('node:crypto');
const path = require('node:path');
const assert = require('node:assert/strict');
const {authSql, install, assertInstalled} = require('./installer.cjs');

const repo = path.resolve(__dirname, '../..');
const external = process.argv.includes('--external');
const project = 'fusioncms-test-' + crypto.randomBytes(6).toString('hex');
const env = {...process.env, COMPOSE_PROFILES: '', FUSION_BIND_ADDRESS: '127.0.0.1', FUSION_HTTP_PORT: '0',
    FUSION_DB_NAME: 'fusioncms', FUSION_DB_USER: 'fusioncms', FUSION_DB_PASSWORD: crypto.randomBytes(24).toString('hex'),
    FUSION_DB_ROOT_PASSWORD: crypto.randomBytes(24).toString('hex')};
const args = ['compose', '--project-directory', repo, '--env-file', path.join(repo, 'docker/.env.example'), '-p', project, '-f', path.join(repo, 'compose.yaml')];
if (!external) args.push('-f', path.join(repo, 'compose.database.yaml'));
args.push('-f', path.join(__dirname, 'compose.test.yaml'));
if (external) args.push('--profile', 'external');
function compose(tail, input) {
    return execFileSync('docker', [...args, ...tail], {cwd: repo, env, input, encoding: 'utf8', windowsHide: true, maxBuffer: 16 * 1024 * 1024, stdio: ['pipe', 'pipe', 'inherit']}).trim();
}
function sql(service, text) {
    return compose(['exec', '-T', service, 'sh', '-c', 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot --batch --skip-column-names'], text);
}
function php(source) {
    return compose(['exec', '-T', '--user', 'www-data', 'cms', 'php', '-r', source]);
}
function baseUrl() {
    return 'http://' + compose(['port', 'cms', '80']).split('\n')[0].trim();
}
const dbService = external ? 'external-db' : 'db';
const hashes = `foreach (['application/config/Database.php','application/config/config.php','application/config/fusion.php','application/modules/donate_mmo/config/donate_mmo.php','application/modules/donate_mmo/manifest.json','writable/install/.lock','writable/uploads/docker-test.txt'] as $file) echo $file . ':' . hash_file('sha256', $file) . PHP_EOL;`;

(async () => {
    try {
        // The base installation must not require a database service or its passwords.
        execFileSync('docker', ['compose', '--env-file', path.join(repo, 'docker/.env.example'), '-f', path.join(repo, 'compose.yaml'), 'config', '--quiet'], {cwd: repo, env});
        compose(['up', '-d', '--build', '--wait', '--wait-timeout', '240']);
        sql('authdb', 'USE fusion_auth;\n' + authSql);
        await install(baseUrl(), {host: dbService, port: '3306', user: env.FUSION_DB_USER, password: env.FUSION_DB_PASSWORD, database: env.FUSION_DB_NAME},
            {host: 'authdb', port: '3306', user: 'root', password: env.FUSION_DB_ROOT_PASSWORD, database: 'fusion_auth'});
        sql(dbService, 'USE fusioncms; CREATE TABLE docker_persistence_probe (value VARCHAR(40)); INSERT INTO docker_persistence_probe VALUES (\'preserved\');');
        php(`require 'application/libraries/ConfigEditor.php'; $c = new ConfigEditor('application/modules/donate_mmo/config/donate_mmo.php'); $c->set('exchange_rate', '123.45'); $c->save(); $f = 'application/modules/donate_mmo/manifest.json'; $m = json_decode(file_get_contents($f), true); $m['enabled'] = false; file_put_contents($f, json_encode($m)); file_put_contents('writable/uploads/docker-test.txt', 'preserved upload'); file_put_contents('writable/backups/docker-secret.txt', 'private backup'); file_put_contents('writable/cache/docker-secret.cache', 'private cache'); file_put_contents('writable/uploads/docker-denied.php', '<?php echo "executable upload";');`);
        const before = php(hashes);
        for (const target of ['/application/config/Database.php', '/docker/state.php', '/composer.lock', '/.env', '/writable/backups/docker-secret.txt', '/writable/cache/docker-secret.cache', '/writable/uploads/docker-denied.php', '/application/modules/donate_mmo/config/donate_mmo.php']) {
            const response = await fetch(baseUrl() + target, {redirect: 'manual'});
            assert.equal(response.status, 403, 'Private path should be denied: ' + target);
        }
        // Replace every container, including both database containers.
        compose(['up', '-d', '--force-recreate', '--wait', '--wait-timeout', '240']);
        assert.equal(php(hashes), before, 'Recreation changed installed files');
        await assertInstalled(baseUrl());
        assert.equal(await (await fetch(baseUrl() + '/writable/uploads/docker-test.txt')).text(), 'preserved upload');
        assert.equal(sql(dbService, 'SELECT value FROM fusioncms.docker_persistence_probe;'), 'preserved');
        assert.equal(sql('authdb', 'SELECT username FROM fusion_auth.account WHERE id = 1;'), 'DOCKEROWNER');
        compose(['down']);
        compose(['up', '-d', '--wait', '--wait-timeout', '240']);
        assert.equal(php(hashes), before, 'Down/up changed installed files');
        await assertInstalled(baseUrl());
        assert.equal(sql(dbService, 'SELECT value FROM fusioncms.docker_persistence_probe;'), 'preserved');
        console.log(`Docker installer and persistence checks passed (${external ? 'external database' : 'bundled MariaDB'}).`);
    } finally {
        // Only this run's random project is removed, including its disposable volumes.
        compose(['down', '--volumes', '--remove-orphans']);
    }
})().catch(error => {console.error(error.message); process.exitCode = 1;});
