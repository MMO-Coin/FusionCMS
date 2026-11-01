const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const https = require('node:https');
const crypto = require('node:crypto');
const {spawn, execFileSync} = require('node:child_process');
const {startInbox} = require('./smtp.cjs');

const repo = path.resolve(__dirname, '../..');
const output = path.join(repo, 'output/playwright');
const manifest = path.join(output, 'runtime.json');
const php = process.env.FUSION_E2E_PHP || 'php';
const port = Number(process.env.FUSION_E2E_PORT || 8098);
const root = fs.mkdtempSync(path.join(os.tmpdir(), 'fusioncms-browser-e2e-'));
const site = path.join(root, 'site');
const configPath = path.join(root, 'fixture.json');
const scenarioPath = path.join(root, 'scenarios.json');
const mailPath = path.join(root, 'mailbox.json');
const id = crypto.randomBytes(6).toString('hex');
const config = {
    root, nonce: crypto.randomBytes(16).toString('hex'), created: [],
    cms: `fusion_e2e_${id}_cms`, auth: `fusion_e2e_${id}_auth`,
    dbHost: process.env.FUSION_E2E_DB_HOST || '127.0.0.1',
    dbUser: process.env.FUSION_E2E_DB_USER || 'root',
    dbPassword: process.env.FUSION_E2E_DB_PASSWORD || 'fusion_test',
    dbPort: Number(process.env.FUSION_E2E_DB_PORT || 3306),
};
let rpc, inbox, child, phpLog, stopping;
function fixture(action) {
    return execFileSync(php, [path.join(__dirname, 'fixture.php'), action, configPath], {encoding: 'utf8', windowsHide: true});
}
function removeRuntime() {
    const resolved = fs.realpathSync(root);
    if (path.dirname(resolved) !== fs.realpathSync(os.tmpdir()) || !path.basename(resolved).startsWith('fusioncms-browser-e2e-')) {
        throw Error('Refusing to remove an unmanaged runtime directory');
    }
    fs.rmSync(resolved, {recursive: true, force: true});
}
async function stop() {
    if (stopping) return stopping;
    stopping = (async () => {
        if (child?.pid && child.exitCode === null && child.signalCode === null) {
            const exited = new Promise(resolve => child.once('exit', resolve));
            child.kill();
            await exited;
        }
        if (phpLog) await new Promise(resolve => phpLog.end(resolve));
        const logs = path.join(site, 'writable/logs');
        if (fs.existsSync(logs)) {
            const entries = fs.readdirSync(logs).filter(name => /^log-.*\.php$/.test(name));
            fs.writeFileSync(path.join(output, 'logs/cms.log'), entries.map(name => fs.readFileSync(path.join(logs, name), 'utf8')).join('\n'));
        }
        let cleanupError;
        try {fixture('cleanup');} catch (error) {cleanupError = error;}
        if (rpc) await new Promise(resolve => rpc.close(resolve));
        if (inbox) await inbox.close();
        if (cleanupError) throw cleanupError;
        if (fs.existsSync(manifest) && JSON.parse(fs.readFileSync(manifest)).root === root) fs.unlinkSync(manifest);
        removeRuntime();
        console.log('Browser fixture schemas and temporary runtime removed.');
    })();
    return stopping;
}
process.on('SIGTERM', () => stop().then(() => process.exit(0), error => {console.error(error.message); process.exit(1);}));
process.on('SIGINT', () => stop().then(() => process.exit(0), error => {console.error(error.message); process.exit(1);}));

(async () => {
    fs.mkdirSync(path.join(output, 'logs'), {recursive: true});
    fs.writeFileSync(configPath, JSON.stringify(config), {mode: 0o600});
    fs.writeFileSync(scenarioPath, '{}');
    fs.writeFileSync(path.join(output, 'logs/rpc.jsonl'), '');
    // Copy tracked working-tree files, excluding local configuration and user-generated data.
    const files = execFileSync('git', ['ls-files', '-z'], {cwd: repo, maxBuffer: 16 * 1024 * 1024}).toString().split('\0');
    for (const file of files) {
        if (!(file === 'index.php' || /^(application|system|vendor|node_modules|writable)\//.test(file))) continue;
        if (/^application\/config\/(Database|fusion|owner)\.php$/i.test(file)) continue;
        const source = path.join(repo, file);
        const target = path.join(site, file);
        if (!fs.existsSync(source)) continue;
        fs.mkdirSync(path.dirname(target), {recursive: true});
        fs.copyFileSync(source, target);
    }
    fs.copyFileSync(path.join(__dirname, 'router.php'), path.join(site, 'router.php'));
    fixture('cert');
    const payments = JSON.parse(fs.readFileSync(path.join(repo, 'tests/fixtures/solana_devnet_payments.json'))).payments;
    const modes = Object.fromEntries([
        ['valid', 'solflare mobile wallet payment'], ['underpay', 'underpay'],
        ['wrong-mint', 'wrong-mint'], ['wrong-recipient', 'wrong-recipient'], ['failed', 'failed'],
    ].map(([mode, name]) => [mode, payments.find(payment => payment.name === name)]));
    if (Object.values(modes).some(payment => !payment)) throw Error('A required recorded payment fixture is missing');
    rpc = https.createServer({key: fs.readFileSync(path.join(root, 'rpc-key.pem')), cert: fs.readFileSync(path.join(root, 'rpc-cert.pem'))}, async (request, response) => {
        if (request.url === '/shutdown' && request.method === 'POST' && request.headers.authorization === `Bearer ${config.nonce}`) {
            response.end('stopping');
            setImmediate(() => stop().catch(error => {console.error(error.message); process.exitCode = 1;}));
            return;
        }
        try {
            if (request.url !== '/' || request.method !== 'POST') {response.writeHead(404).end(); return;}
            let body = '';
            for await (const chunk of request) {
                body += chunk;
                if (body.length > 16384) throw Error('RPC fixture request too large');
            }
            const message = JSON.parse(body);
            fs.appendFileSync(path.join(output, 'logs/rpc.jsonl'), JSON.stringify({method: message.method, params: message.params}) + '\n');
            const scenarios = JSON.parse(fs.readFileSync(scenarioPath));
            let result = null;
            if (message.method === 'getSignaturesForAddress') {
                const mode = scenarios[message.params[0]];
                if (mode === 'outage') {response.writeHead(503).end('Synthetic RPC outage'); return;}
                const payment = modes[mode];
                result = payment ? [{signature: payment.signature, err: payment.transaction.meta.err, confirmationStatus: 'finalized'}] : [];
            } else if (message.method === 'getTransaction') {
                const match = Object.entries(scenarios).find(([, mode]) => modes[mode]?.signature === message.params[0]);
                if (match) {
                    const [reference, mode] = match;
                    const payment = modes[mode];
                    // Adapt public recorded data to the new invoice. No transaction is signed or broadcast.
                    result = JSON.parse(JSON.stringify(payment.transaction).split(payment.invoice.reference).join(reference));
                    result.blockTime = Math.floor(Date.now() / 1000);
                }
            } else {
                throw Error('Unexpected RPC method');
            }
            response.writeHead(200, {'Content-Type': 'application/json'}).end(JSON.stringify({jsonrpc: '2.0', id: message.id, result}));
        } catch (error) {
            response.writeHead(400).end(error.message);
        }
    });
    await new Promise((resolve, reject) => {rpc.once('error', reject); rpc.listen(0, '127.0.0.1', resolve);});
    config.rpcUrl = `https://127.0.0.1:${rpc.address().port}`;
    inbox = await startInbox(mailPath);
    config.smtpPort = inbox.port;
    fs.writeFileSync(configPath, JSON.stringify(config), {mode: 0o600});
    console.log(fixture('setup').trim());
    // The manifest stays outside the served site and is excluded from uploaded CI artifacts.
    fs.writeFileSync(manifest, JSON.stringify({root, configPath, scenarioPath, mailPath, php, rpcUrl: config.rpcUrl, nonce: config.nonce}), {mode: 0o600});
    phpLog = fs.createWriteStream(path.join(output, 'logs/php.log'), {flags: 'w'});
    child = spawn(php, ['-d', `curl.cainfo=${path.join(root, 'rpc-cert.pem')}`, '-d', 'memory_limit=512M', '-S', `127.0.0.1:${port}`, '-t', site, path.join(site, 'router.php')], {
        cwd: site, env: {...process.env, CI_ENVIRONMENT: 'production'}, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
    });
    child.stdout.pipe(phpLog, {end: false}); child.stderr.pipe(phpLog, {end: false});
    child.on('error', error => {console.error(error.message); stop().catch(() => {}).finally(() => process.exit(1));});
    child.on('exit', () => {if (!stopping) stop().catch(() => {}).finally(() => process.exit(1));});
    console.log(`Isolated FusionCMS browser fixture listening on 127.0.0.1:${port}.`);
})().catch(async error => {
    console.error(error.message);
    try {await stop();} catch (cleanupError) {console.error(cleanupError.message);}
    process.exitCode = 1;
});
