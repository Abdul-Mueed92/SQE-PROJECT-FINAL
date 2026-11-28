const { expect } = require('@playwright/test');
const { randomUUID } = require('node:crypto');

const company = 1;
const password = '123456';
const actors = { admin: 'test@company.com', customer: 'customer@company.com' };
const headers = { Authorization: `Basic ${Buffer.from(`${actors.admin}:${password}`).toString('base64')}` };

function uniqueNumber(label) {
    return `SQE-${label}-${randomUUID().slice(0, 8)}`;
}

async function api(request, method, path, data) {
    if (/^documents(?:\/[^/]+)?$/.test(path)) path += '?search=type:invoice';
    const response = await request.fetch(`/api/${path}`, { method, headers, data });
    expect(response.ok(), `${method} /api/${path}: ${await response.text()}`).toBe(true);
    return (await response.json()).data;
}

async function signIn(page, role, baseURL = '') {
    await page.goto(`${baseURL}/auth/login`, { waitUntil: 'commit' });
    const token = await page.locator('input[name="_token"]').first().getAttribute('value');
    const login = await page.request.post(page.url(), {
        form: { _token: token, email: actors[role], password },
        headers: { Accept: 'application/json' }, timeout: 60000,
    });
    expect(login.ok(), `synthetic ${role} login HTTP status`).toBe(true);
    const result = await login.json();
    expect(result.success, `synthetic ${role} login`).toBe(true);
    const target = new URL(result.redirect, page.url());
    expect(target.origin).toBe(new URL(page.url()).origin);
    expect(target.pathname).toMatch(role === 'customer' ? /^\/1\/portal(?:\/|$)/ : /^\/1(?:\/|$)/);
}

async function seedInvoice(request, status = 'sent', number = uniqueNumber('BROWSER')) {
    const categories = await api(request, 'GET', 'categories');
    const category = categories.find((record) => record.type === 'income');
    return api(request, 'POST', 'documents', {
        type: 'invoice', status, document_number: number,
        issued_at: '2026-11-01 12:00:00', due_at: '2026-11-30 12:00:00',
        amount: 100, currency_code: 'USD', currency_rate: 1,
        contact_id: 1, contact_name: 'Test Customer', category_id: category.id,
        items: [{ name: 'SQE service', quantity: '1', price: 100 }],
    });
}

async function seedItem(request) {
    return api(request, 'POST', 'items', {
        type: 'service', name: uniqueNumber('ITEM'), sale_price: 100, enabled: 1,
    });
}

module.exports = { api, company, seedInvoice, seedItem, signIn, uniqueNumber };
