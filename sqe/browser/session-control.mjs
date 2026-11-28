import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const baseURL = process.env.SQE_BROWSER_URL || 'http://127.0.0.1:8765';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(baseURL)) throw new Error('Use the synthetic loopback server.');
const url = `${baseURL}/auth/login`;
const response = await fetch(url, { signal: AbortSignal.timeout(60000) });
const cookies = response.headers.getSetCookie().map((header) => {
    const [cookie, ...attributes] = header.split(';').map((part) => part.trim());
    return { name: cookie.split('=', 1)[0], attributes };
});
const session = cookies.find(({ name }) => name.toLowerCase().endsWith('session'));
const attributes = session?.attributes.map((attribute) => attribute.toLowerCase()) || [];
const observation = { url, status: response.status, cookies, configuration: 'config/session.php' };
const evidence = fileURLToPath(new URL('../evidence/security/', import.meta.url));
await mkdir(evidence, { recursive: true });
await writeFile(`${evidence}/session-cookie-attributes.json`, JSON.stringify(observation, null, 2));
if (!response.ok || !attributes.includes('httponly') || !attributes.includes('samesite=lax')) {
    process.exitCode = 1;
}
