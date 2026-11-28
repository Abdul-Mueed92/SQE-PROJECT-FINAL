import { mkdir, writeFile } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';

const zap = process.env.SQE_ZAP_PROXY || 'http://127.0.0.1:8091';
const target = process.env.SQE_BROWSER_URL || 'http://127.0.0.1:8765';
for (const url of [zap, target]) {
    if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(url)) throw new Error('Use only the loopback ZAP and SQE test servers.');
}

const evidence = process.env.SQE_ZAP_EVIDENCE || fileURLToPath(new URL('../evidence/security/', import.meta.url));
await mkdir(evidence, { recursive: true });

async function call(path) {
    const response = await fetch(zap + path);
    if (!response.ok) throw new Error(`ZAP API status ${response.status} for ${path}`);
    return response.json();
}

await call('/JSON/core/action/setMode/?mode=safe');
const mode = await call('/JSON/core/view/mode/');
if (mode.mode !== 'safe') throw new Error('ZAP did not enter safe mode.');

const browserDirectory = fileURLToPath(new URL('./', import.meta.url));
const status = await new Promise((resolve, reject) => {
    const child = spawn(process.execPath, ['node_modules/@playwright/test/cli.js', 'test',
        '--config', 'playwright.config.cjs', '--grep', 'INV-UI-01|PAY-UI-01'], {
        cwd: browserDirectory,
        env: { ...process.env, SQE_ZAP_PROXY: zap, SQE_BROWSER_EVIDENCE: `${evidence}/proxy-browser` },
        stdio: 'inherit',
    });
    child.once('error', reject);
    child.once('close', resolve);
});

let pending = Infinity;
for (let check = 0; check < 180; check++) {
    pending = Number((await call('/JSON/pscan/view/recordsToScan/')).recordsToScan);
    if (pending === 0) break;
    await delay(1000);
}
const base = encodeURIComponent(target);
const alerts = await call(`/JSON/alert/view/alerts/?baseurl=${base}&start=0&count=500`);
const urls = await call(`/JSON/core/view/urls/?baseurl=${base}`);
const version = await call('/JSON/core/view/version/');
await writeFile(`${evidence}/zap-passive.json`, JSON.stringify({ version, mode, target, status, pending, urls, alerts }, null, 2));
if (status !== 0 || pending !== 0 || urls.urls.length === 0) process.exitCode = 1;
