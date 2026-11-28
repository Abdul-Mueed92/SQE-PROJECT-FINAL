const { defineConfig } = require('@playwright/test');
const path = require('node:path');

const baseURL = process.env.SQE_BROWSER_URL || 'http://127.0.0.1:8765';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(baseURL)) {
    throw new Error('Browser fixtures require the synthetic loopback server.');
}
const evidence = process.env.SQE_BROWSER_EVIDENCE || path.resolve(__dirname, '../evidence/browser');

module.exports = defineConfig({
    testDir: '.',
    testMatch: '*.spec.cjs',
    workers: 1,
    retries: 0,
    timeout: 180000,
    outputDir: path.join(evidence, 'test-results'),
    expect: { timeout: 12000 },
    reporter: [['list'], ['junit', { outputFile: path.join(evidence, 'junit.xml') }],
        ['html', { outputFolder: path.join(evidence, 'html'), open: 'never' }]],
    use: {
        baseURL,
        actionTimeout: 30000,
        navigationTimeout: 60000,
        serviceWorkers: 'block',
        trace: 'retain-on-failure',
        launchOptions: {
            executablePath: process.env.SQE_CHROMIUM,
            args: process.env.SQE_ZAP_PROXY ? ['--proxy-bypass-list=<-loopback>'] : [],
        },
        proxy: process.env.SQE_ZAP_PROXY ? { server: process.env.SQE_ZAP_PROXY } : undefined,
    },
});
