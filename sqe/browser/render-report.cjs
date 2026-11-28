const { chromium } = require('playwright');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

async function main() {
    if (!process.argv[2]) throw new Error('Provide the destination PDF path.');
    const browser = await chromium.launch({ executablePath: process.env.SQE_CHROMIUM });
    try {
        const page = await browser.newPage();
        await page.goto(pathToFileURL(path.resolve(__dirname, '../report.html')).href);
        await page.pdf({ path: path.resolve(process.argv[2]), printBackground: true, preferCSSPageSize: true });
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
