const assert = require('node:assert/strict');

const authSql = `
CREATE TABLE account (id INT PRIMARY KEY, username VARCHAR(32), sha_pass_hash VARCHAR(40), email VARCHAR(100), joindate DATETIME, last_ip VARCHAR(45), last_login DATETIME, expansion INT DEFAULT 0);
INSERT INTO account VALUES (1, 'DOCKEROWNER', UPPER(SHA1('DOCKEROWNER:DOCKERTEST123')), 'owner@example.invalid', NOW(), '127.0.0.1', NOW(), 2);
CREATE TABLE account_access (AccountId INT, SecurityLevel INT, RealmID INT DEFAULT -1);
CREATE TABLE account_banned (id INT, banreason VARCHAR(255), active INT, bandate INT, unbandate INT, bannedby VARCHAR(50));
CREATE TABLE ip_banned (ip VARCHAR(45), banreason VARCHAR(255), bandate INT, unbandate INT, bannedby VARCHAR(50));
`;

async function install(base, cms, auth, {apache = true} = {}) {
    const page = await fetch(base + '/install');
    assert.equal(page.status, 200);
    assert.match(await page.text(), /id="cms_hostname"/);
    async function step(name, values, expected = '1') {
        const response = await fetch(base + '/install/next?step=' + name, values ? {
            method: 'POST', body: new URLSearchParams(values),
        } : {});
        assert.equal(response.status, 200, name + ' status');
        assert.equal((await response.text()).trim(), expected, name + ' result');
    }
    await step('checkPhpVersion');
    await step('checkPhpExtensions');
    if (apache) await step('checkApacheModules');
    for (const [path, folder] of [['application', 'config'], ['application', 'modules'], ['writable', 'cache'], ['writable', 'backups'], ['writable', 'logs'], ['writable', 'uploads']]) {
        const response = await fetch(base + `/install/next?step=folder&test=${folder}&path=${path}`);
        assert.equal((await response.text()).trim(), '1', 'Installer write access: ' + folder);
    }
    await step('setAndCheckDbConnection', {
        cms_hostname: cms.host, cms_port: cms.port, cms_username: cms.user, cms_password: cms.password, cms_database: cms.database,
        auth_hostname: auth.host, auth_port: auth.port, auth_username: auth.user, auth_password: auth.password, auth_database: auth.database,
    });
    const authSettings = {realmd_account_encryption: 'SPH', realmd_rbac: 'false', realmd_battle_net: 'false', realmd_totp_secret: 'false', realmd_totp_secret_name: 'totp_secret'};
    await step('checkAuthConfig', authSettings);
    await step('config', {
        title: 'Docker Installer Test', server_name: 'Docker Fixture', realmlist: 'example.invalid',
        max_expansion: '2', keywords: '', description: 'Disposable installer test', analytics: '',
        captcha: 'disabled', site_key: '', secret_key: '', cdn: '0', cdn_link: '',
        security_code: 'DockerFixtureOnly', superadmin: 'DOCKEROWNER', ...authSettings,
    });
    await step('database');
    // Connection checks use the disposable auth schema for both realm databases.
    // This tests the installer without running a game server or emulating its tables.
    await step('realms', {realms: JSON.stringify([{
        hostname: auth.host, username: auth.user, password: auth.password,
        characters: auth.database, world: auth.database, db_port: auth.port,
        cap: '100', realm_expansion: '2', realmName: 'Docker Fixture',
        console_username: 'fixture', console_password: 'fixture', console_port: '7878',
        emulator: 'trinity_old', port: '8085',
    }]), insert: 'false'});
    await step('final', undefined, 'success');
    await assertInstalled(base);
}

async function assertInstalled(base) {
    const login = await fetch(base + '/login');
    assert.equal(login.status, 200);
    const html = await login.text();
    assert.match(html, /Docker Installer Test/);
    assert.match(html, /id="floatingUser"/);
    assert(login.headers.getSetCookie().some(cookie => cookie.startsWith('csrf_cookie_name=')), 'Installed site did not issue a CSRF cookie');
    for (const match of html.matchAll(/(?:src|href)="([^"]+\.(?:js|css)(?:\?[^"]*)?)"/g)) {
        const asset = new URL(match[1], base);
        if (asset.origin === new URL(base).origin) {
            const response = await fetch(asset);
            assert.equal(response.status, 200, 'Installed page asset: ' + asset.pathname);
        }
    }
    const installer = await fetch(base + '/install/next?step=checkPhpVersion', {redirect: 'manual'});
    assert.notEqual((await installer.text()).trim(), '1', 'Installer reopened after completion');
}

module.exports = {authSql, install, assertInstalled};
