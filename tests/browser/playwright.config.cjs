const path = require('node:path');
const {defineConfig, devices} = require('@playwright/test');

const output = process.env.FUSION_E2E_OUTPUT_DIR || path.resolve(__dirname, '../../output/playwright');
const port = Number(process.env.FUSION_E2E_PORT || 8098);
if (!Number.isInteger(port) || port < 1024 || port > 65535) throw Error('Invalid FUSION_E2E_PORT');

module.exports = defineConfig({
    testDir: __dirname,
    testMatch: '*.spec.cjs',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 60000,
    globalTimeout: 600000,
    expect: {timeout: 10000},
    outputDir: path.join(output, 'results'),
    reporter: [['list'], ['html', {outputFolder: path.join(output, 'report'), open: 'never'}]],
    globalTeardown: require.resolve('./teardown.cjs'),
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    projects: [
        {name: 'desktop', use: {...devices['Desktop Chrome']}},
        {name: 'mobile', use: {...devices['Pixel 7'], defaultBrowserType: 'chromium'}},
    ],
    webServer: {
        command: 'node server.cjs',
        cwd: __dirname,
        url: `http://127.0.0.1:${port}/login`,
        timeout: 180000,
        reuseExistingServer: false,
        gracefulShutdown: {signal: 'SIGTERM', timeout: 15000},
        stdout: 'pipe',
        stderr: 'pipe',
    },
});
