import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { request } from 'playwright';
import fixtures from './fixtures.cjs';

const baseURL = process.env.SQE_BROWSER_URL || 'http://127.0.0.1:8765';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(baseURL)) throw new Error('Use the synthetic loopback server.');
const staff = await request.newContext({ baseURL, timeout: 60000 });
const customer = await request.newContext({ baseURL, timeout: 60000 });

async function login(client, email, role) {
    const response = await client.get('/auth/login');
    const token = (await response.text()).match(/name="_token"[^>]+value="([^"]+)"/)?.[1];
    if (!token) throw new Error('Could not locate the login form token.');
    const result = await client.post('/auth/login', {
        form: { _token: token, email, password: '123456' }, headers: { Accept: 'application/json' },
    });
    const body = await result.json();
    const path = body.redirect ? new URL(body.redirect).pathname : '';
    if (!result.ok() || !body.success || path.includes('/portal') !== (role === 'customer')) {
        throw new Error(`Synthetic ${role} login failed.`);
    }
}

try {
    const invoice = await fixtures.seedInvoice(staff);
    await login(staff, 'test@company.com', 'staff');
    await login(customer, 'customer@company.com', 'customer');
    const screens = [
        ['invoice-create', '/1/sales/invoices/create', /New Invoice/],
        ['payment-form', `/1/modals/documents/${invoice.id}/transactions/create`, /form-transaction/],
    ];
    const decisions = [];
    for (const [role, client] of [['staff', staff], ['customer', customer]]) {
        for (const [action, endpoint, evidence] of screens) {
            const response = await client.get(endpoint, { maxRedirects: 0 });
            const exposedForm = evidence.test(await response.text());
            const status = response.status();
            const redirected = [301, 302, 303, 307, 308].includes(status) && response.headers().location;
            const allowed = status === 200 && exposedForm;
            const denied = !exposedForm && (status === 403 || Boolean(redirected));
            decisions.push({ role, action, endpoint, expected: role === 'staff' ? 'allow' : 'deny',
                observed: allowed ? 'allow' : denied ? 'deny' : 'unexpected', status,
                location: response.headers().location, exposedForm });
        }
    }
    const directory = fileURLToPath(new URL('../evidence/security/', import.meta.url));
    await mkdir(directory, { recursive: true });
    await writeFile(`${directory}/role-decisions.json`, JSON.stringify(decisions, null, 2));
    console.log(JSON.stringify(decisions, null, 2));
    if (decisions.some(({ expected, observed }) => expected !== observed)) process.exitCode = 1;
} finally {
    await staff.dispose();
    await customer.dispose();
}
