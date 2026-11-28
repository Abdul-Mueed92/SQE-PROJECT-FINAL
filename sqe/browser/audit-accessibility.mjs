import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { chromium, request } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import lighthouse from 'lighthouse';
import { launch } from 'chrome-launcher';
import fixtures from './fixtures.cjs';

const phase = process.argv[2];
if (!['before', 'after'].includes(phase)) throw new Error('Provide an evidence phase, before or after.');
const baseURL = process.env.SQE_BROWSER_URL || 'http://127.0.0.1:8765';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(baseURL)) throw new Error('Run on the synthetic loopback server.');

const directory = fileURLToPath(new URL(`../evidence/accessibility/${phase}/`, import.meta.url));
await mkdir(directory, { recursive: true });
const chrome = await launch({ chromePath: process.env.SQE_CHROMIUM, chromeFlags: ['--headless=new'] });
const browser = await chromium.connectOverCDP(`http://localhost:${chrome.port}`);
const api = await request.newContext({ baseURL });

try {
    const invoice = await fixtures.seedInvoice(api);
    const page = await browser.contexts()[0].newPage();
    page.setDefaultTimeout(60000);
    await page.route('**/*', (route) => {
        if (route.request().url().startsWith(baseURL + '/')) return route.continue();
        return route.abort();
    });
    await fixtures.signIn(page, 'admin', baseURL);

    const pages = {
        'invoice-creation': '/1/sales/invoices/create',
        'payment-recording': `/1/sales/invoices/${invoice.id}`,
    };
    const overview = [];
    for (const [name, route] of Object.entries(pages)) {
        const url = `${baseURL}${route}`;
        await page.goto(url, { waitUntil: 'commit' });
        await page.getByRole('heading', { level: 1 }).first().waitFor();
        const axe = await new AxeBuilder({ page }).analyze();
        await writeFile(`${directory}/${name}.axe.json`, JSON.stringify(axe, null, 2));

        const run = await lighthouse(url, {
            port: chrome.port, output: ['json', 'html'], logLevel: 'error', disableStorageReset: true,
            onlyCategories: ['accessibility', 'best-practices', 'seo'],
        });
        if (new URL(run.lhr.finalDisplayedUrl).pathname !== new URL(url).pathname) {
            throw new Error(`Lighthouse did not audit the requested authenticated screen: ${run.lhr.finalDisplayedUrl}`);
        }
        const [json, html] = run.report;
        await writeFile(`${directory}/${name}.lighthouse.json`, json);
        await writeFile(`${directory}/${name}.lighthouse.html`, html);
        overview.push({ screen: name, url: run.lhr.finalDisplayedUrl,
            axeViolations: axe.violations.map(({ id, impact, nodes }) => ({ id, impact, nodes: nodes.length })),
            lighthouse: Object.fromEntries(Object.entries(run.lhr.categories).map(([id, result]) => [id, result.score])),
        });
    }
    await writeFile(`${directory}/summary.json`, JSON.stringify(overview, null, 2));
    console.log(JSON.stringify(overview, null, 2));
} finally {
    await api.dispose();
    await browser.close();
    await chrome.kill();
}
