const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

async function main() {
    const fields = Object.fromEntries(Object.entries({
        'verification-url': '/verify', 'expires-at': String(Math.floor(Date.now() / 1000) + 3600),
        'payment-csrf-name': 'csrf_token_name', 'payment-csrf-hash': 'initial',
        'payment-status': '', 'expiration-timer': '', 'solana-qr-container': '',
    }).map(([id, value]) => [id, {value, style: {}, textContent: ''}]));
    const timers = [];
    const requests = [];
    const context = {
        document: {getElementById: id => fields[id], addEventListener: (event, callback) => callback()},
        Config: {URL: '/'}, URLSearchParams, Date,
        setInterval: () => 1, clearInterval: () => {}, setTimeout: callback => timers.push(callback),
        window: {location: {}},
        fetch: async (url, options) => {
            requests.push({url, options});
            return {status: 200, json: async () => ({status: requests.length === 1 ? 'pending' : 'success', csrf_hash: 'renewed'})};
        },
    };
    vm.runInNewContext(fs.readFileSync('application/modules/donate_mmo/js/checkout.js', 'utf8'), context);
    await timers.shift()();
    assert.equal(requests[0].options.method, 'POST');
    assert.equal(requests[0].options.body.get('csrf_token_name'), 'initial');
    assert.equal(fields['payment-csrf-hash'].value, 'renewed');
    await timers.shift()();
    assert.equal(requests[1].options.body.get('csrf_token_name'), 'renewed');
    assert.match(fields['payment-status'].textContent, /credited/);
    console.log('PASS: checkout sends POST and renews the CSRF token before the next poll');

    const adminFields = {'mmo-admin-csrf-name': {value: 'csrf_token_name'}, 'mmo-admin-csrf-hash': {value: 'admin-initial'}};
    let reloaded = false;
    let submitted;
    const adminContext = {
        document: {getElementById: id => adminFields[id]}, Config: {URL: '/'}, URLSearchParams,
        window: {location: {reload: () => { reloaded = true; }}}, Swal: {fire: () => { throw new Error('Unexpected error dialog'); }},
        fetch: async (url, options) => {
            submitted = {url, options};
            return {ok: true, headers: {get: () => 'admin-renewed'}, text: async () => 'SUCCESS'};
        },
    };
    vm.runInNewContext(fs.readFileSync('application/modules/donate_mmo/js/admin.js', 'utf8'), adminContext);
    await adminContext.Admin.post('delete', {id: 3});
    assert.equal(submitted.url, '/donate_mmo/admin/delete');
    assert.equal(submitted.options.method, 'POST');
    assert.equal(submitted.options.body.get('id'), '3');
    assert.equal(submitted.options.body.get('csrf_token_name'), 'admin-initial');
    assert.equal(adminFields['mmo-admin-csrf-hash'].value, 'admin-renewed');
    assert.equal(reloaded, true);
    console.log('PASS: package admin sends POST, includes the package ID and accepts the renewed CSRF token');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
