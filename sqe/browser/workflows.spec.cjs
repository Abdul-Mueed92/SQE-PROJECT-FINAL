const { expect, test } = require('@playwright/test');
const { api, company, seedInvoice, seedItem, signIn, uniqueNumber } = require('./fixtures.cjs');

const invoiceCreate = `/${company}/sales/invoices/create`;
const invoicePage = (id) => `/${company}/sales/invoices/${id}`;

test.beforeEach(async ({ page, baseURL }) => {
    await page.route('**/*', (route) => route.request().url().startsWith(`${baseURL}/`)
        ? route.continue()
        : route.abort());
});

async function addInvoiceItem(page, item) {
    await page.getByRole('button', { name: /Add an Item/ }).click();
    await page.getByRole('textbox', { name: 'Type an item name' }).fill(item.name);
    await page.getByText(item.name, { exact: true }).filter({ visible: true }).first().click();
}

async function fillInvoice(page, item, number, withCustomer = true) {
    await page.goto(invoiceCreate, { waitUntil: 'commit' });
    await expect(page.getByRole('heading', { name: 'New Invoice' })).toBeVisible();
    if (withCustomer) {
        await page.getByRole('button', { name: /Add a Customer/ }).click();
        await page.getByText('Test Customer', { exact: true }).filter({ visible: true }).first().click();
    }
    await addInvoiceItem(page, item);
    await page.locator('#document_number').fill(number);
}

async function openPayment(page, id) {
    await page.goto(invoicePage(id), { waitUntil: 'domcontentloaded' });
    const button = page.locator('#show-slider-actions-payment-invoice');
    await expect(button).toBeVisible({ timeout: 60000 });
    await expect(button).not.toHaveAttribute('@click', /./, { timeout: 60000 });
    const [response] = await Promise.all([
        page.waitForResponse((result) => result.url().includes(`/documents/${id}/transactions/create`), { timeout: 60000 }),
        button.click(),
    ]);
    expect(response.ok(), `payment modal HTTP status ${response.status()}`).toBe(true);
    expect((await response.json()).success).toBe(true);
    const form = page.locator('#form-transaction').filter({ visible: true });
    await expect(form).toBeVisible({ timeout: 60000 });
    return form;
}

async function selectCash(page, placeholder) {
    const select = page.getByPlaceholder(placeholder, { exact: true }).filter({ visible: true });
    await select.focus();
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Tab');
    await expect(select).toHaveValue(/^Cash/);
}

async function changeLastCent(amount, digit, shown) {
    await amount.press('End');
    await amount.press('Shift+ArrowLeft');
    await amount.press(digit);
    await expect(amount).toHaveValue(shown);
}

test('INV-UI-01 creates a draft invoice from the browser and reads it back', async ({ page, request }) => {
    const item = await seedItem(request);
    const number = uniqueNumber('UI-INVOICE');
    await signIn(page, 'admin');
    await fillInvoice(page, item, number);

    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.waitForURL(new RegExp(`/${company}/sales/invoices/([0-9]+)(?:[?#]|$)`), { waitUntil: 'commit', timeout: 60000 });
    await expect(page.getByRole('heading', { name: new RegExp(number) })).toBeVisible();
    const id = new URL(page.url()).pathname.split('/').at(-1);
    const invoice = await api(request, 'GET', `documents/${id}`);
    expect(invoice).toMatchObject({ document_number: number, status: 'draft' });
    expect(Number(invoice.amount)).toBe(100);
});

test('PAY-UI-01 explains an excessive payment and accepts a corrected payment', async ({ page, request }) => {
    const invoice = await seedInvoice(request);
    await signIn(page, 'admin');
    const form = await openPayment(page, invoice.id);
    const amount = form.locator('input[name="amount"]').filter({ visible: true });
    await selectCash(page, '- Select Account -');
    await selectCash(page, '- Select Payment Method -');
    await expect(amount).toHaveValue('$100.00');
    await changeLastCent(amount, '1', '$100.01');
    const save = page.getByRole('button', { name: 'Save', exact: true }).filter({ visible: true });
    await save.click();
    await expect(page.getByText(/passes the total|payment not added/i).filter({ visible: true }).first()).toBeVisible();
    expect(await api(request, 'GET', `documents/${invoice.id}/transactions`)).toHaveLength(0);

    await changeLastCent(amount, '0', '$100.00');
    await expect(amount).toBeFocused();
    for (let tab = 0; tab < 12 && !(await save.evaluate((element) => element === document.activeElement)); tab++) {
        await page.keyboard.press('Tab');
    }
    await expect(save).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(form).toBeHidden();
    await expect(page.getByText('Paid', { exact: true }).filter({ visible: true }).first()).toBeVisible();
    expect(await api(request, 'GET', `documents/${invoice.id}`)).toMatchObject({ status: 'paid' });
    const payments = await api(request, 'GET', `documents/${invoice.id}/transactions`);
    expect(payments).toHaveLength(1);
    expect(Number(payments[0].amount)).toBe(100);
});

test('S2-INV-02 creates a draft with two line items and a note', async ({ page, request }) => {
    const items = [await seedItem(request), await seedItem(request)];
    const number = uniqueNumber('MULTI-LINE');
    const note = 'Section 2 two-line invoice note';
    await signIn(page, 'admin');
    await fillInvoice(page, items[0], number);
    await addInvoiceItem(page, items[1]);
    await page.locator('textarea[name="notes"]').fill(note);
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.waitForURL(/\/sales\/invoices\/\d+$/, { waitUntil: 'commit', timeout: 60000 });
    await expect(page.getByRole('heading', { name: new RegExp(number) })).toBeVisible();
    const invoice = await api(request, 'GET', `documents/${number}`);
    expect(invoice).toMatchObject({ document_number: number, status: 'draft', notes: note });
    expect(Number(invoice.amount)).toBe(200);
    expect(invoice.items.data.map(({ name }) => name).sort()).toEqual(items.map(({ name }) => name).sort());
});

test('S2-INV-03 rejects a duplicate invoice number and preserves the original', async ({ page, request }) => {
    const original = await seedInvoice(request, 'draft');
    const item = await seedItem(request);
    await signIn(page, 'admin');
    await fillInvoice(page, item, original.document_number);
    const [response] = await Promise.all([
        page.waitForResponse((candidate) => candidate.request().method() === 'POST'
            && new URL(candidate.url()).pathname === `/${company}/sales/invoices`),
        page.getByRole('button', { name: 'Save', exact: true }).click(),
    ]);
    expect(response.status()).toBe(422);
    expect((await response.json()).errors).toHaveProperty('document_number');
    await expect(page.getByText(/already been taken/i).filter({ visible: true }).first()).toBeVisible();
    const after = await api(request, 'GET', `documents/${original.document_number}`);
    expect(after.id).toBe(original.id);
    expect(after.items.data.map(({ name }) => name)).toEqual(['SQE service']);
});

test('S2-INV-05 rejects an invoice without a customer', async ({ page, request }) => {
    const item = await seedItem(request);
    const number = uniqueNumber('NO-CUSTOMER');
    await signIn(page, 'admin');
    await fillInvoice(page, item, number, false);
    const [response] = await Promise.all([
        page.waitForResponse((candidate) => candidate.request().method() === 'POST'
            && new URL(candidate.url()).pathname === `/${company}/sales/invoices`),
        page.getByRole('button', { name: 'Save', exact: true }).click(),
    ]);
    expect(response.status()).toBe(422);
    expect((await response.json()).errors).toHaveProperty('contact_name');
    await expect(page.getByRole('heading', { name: 'New Invoice' })).toBeVisible();
    await expect(page.locator('#document_number')).toHaveValue(number);
});

test('S2-INV-04 changes draft quantity and preserves the same invoice record', async ({ page, request }) => {
    const invoice = await seedInvoice(request, 'draft');
    await signIn(page, 'admin');
    await page.goto(`${invoicePage(invoice.id)}/edit`, { waitUntil: 'commit' });
    const quantity = page.locator('input[data-item="quantity"]').filter({ visible: true });
    await expect(quantity).toHaveValue('1');
    await quantity.fill('2');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.waitForURL((url) => url.pathname === invoicePage(invoice.id), { waitUntil: 'commit', timeout: 60000 });
    const after = await api(request, 'GET', `documents/${invoice.id}`);
    expect(after).toMatchObject({ id: invoice.id, status: 'draft' });
    expect(Number(after.amount)).toBe(200);
    expect(Number(after.items.data[0].total)).toBe(200);
    await expect(page.getByRole('row', { name: /SQE service 2 / })).toBeVisible();
});

test('S2-INV-06 replaces a draft line item and keeps the original invoice ID', async ({ page, request }) => {
    const invoice = await seedInvoice(request, 'draft');
    const item = await seedItem(request);
    await signIn(page, 'admin');
    await page.goto(`${invoicePage(invoice.id)}/edit`, { waitUntil: 'commit' });
    await addInvoiceItem(page, item);
    const original = page.getByRole('row', { name: /^list SQE service .* delete$/ });
    await original.getByRole('button', { name: 'delete', exact: true }).click();
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.waitForURL((url) => url.pathname === invoicePage(invoice.id), { waitUntil: 'commit', timeout: 60000 });
    const after = await api(request, 'GET', `documents/${invoice.id}`);
    expect(after).toMatchObject({ id: invoice.id, status: 'draft' });
    expect(after.items.data.map(({ name }) => name)).toEqual([item.name]);
});

test('S2-INV-07 rejects replacing a draft invoice number with an existing number', async ({ page, request }) => {
    const original = await seedInvoice(request, 'draft');
    const other = await seedInvoice(request, 'draft');
    await signIn(page, 'admin');
    await page.goto(`${invoicePage(original.id)}/edit`, { waitUntil: 'commit' });
    await page.locator('#document_number').fill(other.document_number);
    await Promise.all([
        page.waitForResponse((candidate) => ['PATCH', 'PUT', 'POST'].includes(candidate.request().method())
            && new URL(candidate.url()).pathname === invoicePage(original.id), { timeout: 60000 }),
        page.getByRole('button', { name: 'Save', exact: true }).click(),
    ]);
    const after = await api(request, 'GET', `documents/${original.id}`);
    expect(after).toMatchObject({ id: original.id, document_number: original.document_number });
    expect(await api(request, 'GET', `documents/${other.document_number}`)).toMatchObject({ id: other.id });
});

test('S2-INV-08 edits the due date and prevents selecting a date before issue', async ({ page, request }) => {
    const invoice = await seedInvoice(request, 'draft');
    await signIn(page, 'admin');
    await page.goto(`${invoicePage(invoice.id)}/edit`, { waitUntil: 'commit' });
    await page.getByRole('textbox', { name: 'Enter Due Date' }).click();
    const calendar = page.locator('.flatpickr-calendar.open');
    await expect(calendar.locator('.flatpickr-prev-month')).toHaveClass(/flatpickr-disabled/);
    await calendar.locator('.flatpickr-day[aria-label="November 29, 2026"]').click();
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.waitForURL((url) => url.pathname === invoicePage(invoice.id), { waitUntil: 'commit', timeout: 60000 });
    const after = await api(request, 'GET', `documents/${invoice.id}`);
    expect(after).toMatchObject({ id: invoice.id, status: 'draft' });
    expect(after.due_at).toMatch(/^2026-11-29/);
});

test('S2-PAY-04 records a partial payment and then the remainder', async ({ page, request }) => {
    const invoice = await seedInvoice(request);
    await signIn(page, 'admin');
    for (const [input, status, amounts] of [['40.00', 'partial', [40]], ['60.00', 'paid', [40, 60]]]) {
        const form = await openPayment(page, invoice.id);
        await selectCash(page, '- Select Account -');
        await selectCash(page, '- Select Payment Method -');
        const amount = form.locator('input[name="amount"]').filter({ visible: true });
        if (status === 'partial') {
            await amount.press('Control+a');
            await amount.pressSequentially('40.00');
        }
        await expect(amount).toHaveValue(`$${input}`);
        await page.getByRole('button', { name: 'Save', exact: true }).filter({ visible: true }).click();
        await expect(form).toBeHidden({ timeout: 60000 });
        expect(await api(request, 'GET', `documents/${invoice.id}`)).toMatchObject({ status });
        const completed = await api(request, 'GET', `documents/${invoice.id}/transactions`);
        expect(completed.map((payment) => Number(payment.amount)).sort((a, b) => a - b)).toEqual(amounts);
    }
});

test('S2-PAY-05 rejects a payment without an account and leaves the invoice unchanged', async ({ page, request }) => {
    const invoice = await seedInvoice(request);
    await signIn(page, 'admin');
    const form = await openPayment(page, invoice.id);
    await selectCash(page, '- Select Payment Method -');
    const account = form.getByPlaceholder('- Select Account -', { exact: true }).filter({ visible: true });
    await account.hover();
    const clearAccount = form.locator('.el-select:has(input[placeholder="- Select Account -"]) .el-icon-circle-close');
    await clearAccount.click();
    await expect(account).toHaveValue('');
    await page.getByRole('button', { name: 'Save', exact: true }).filter({ visible: true }).click();
    await expect(form).toBeVisible();
    await expect(form.getByText(/account.*required/i).first()).toBeVisible();
    expect(await api(request, 'GET', `documents/${invoice.id}/transactions`)).toHaveLength(0);
    expect(await api(request, 'GET', `documents/${invoice.id}`)).toMatchObject({ status: 'sent' });
});

test('S2-PAY-02 dismisses and reopens the payment form without a write', async ({ page, request }) => {
    const invoice = await seedInvoice(request);
    await signIn(page, 'admin');
    const form = await openPayment(page, invoice.id);
    await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(form).toBeHidden();
    await openPayment(page, invoice.id);
    expect(await api(request, 'GET', `documents/${invoice.id}/transactions`)).toHaveLength(0);
    expect(await api(request, 'GET', `documents/${invoice.id}`)).toMatchObject({ status: 'sent' });
});

for (const status of ['paid', 'cancelled']) {
    test(`S2-PAY-03 prevents the staff payment action for a ${status} invoice`, async ({ page, request }) => {
        const invoice = await seedInvoice(request, status);
        await signIn(page, 'admin');
        await page.goto(invoicePage(invoice.id), { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { name: new RegExp(invoice.document_number) })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Add Payment', exact: true }).and(page.locator(':enabled'))).toHaveCount(0);
        expect(await api(request, 'GET', `documents/${invoice.id}/transactions`)).toHaveLength(0);
        expect(await api(request, 'GET', `documents/${invoice.id}`)).toMatchObject({ status });
    });
}

test('SEC-01 staff can open the invoice creation form', async ({ page }) => {
    await signIn(page, 'admin');
    await page.goto(invoiceCreate, { waitUntil: 'commit' });
    await expect(page.getByRole('heading', { name: 'New Invoice' })).toBeVisible();
});

test('SEC-02 staff can open the invoice payment form', async ({ page, request }) => {
    const invoice = await seedInvoice(request);
    await signIn(page, 'admin');
    const response = await page.request.get(`/${company}/modals/documents/${invoice.id}/transactions/create`);
    expect(response.ok()).toBe(true);
    const body = await response.json();
    expect(body.success).toBe(true);
    expect(body.html).toContain('form-transaction');
});

test('SEC-03 customer is denied the staff invoice creation form', async ({ page }) => {
    await signIn(page, 'customer');
    const response = await page.goto(invoiceCreate, { waitUntil: 'domcontentloaded' });
    expect(response.status() === 403 || !new URL(page.url()).pathname.endsWith(invoiceCreate)).toBe(true);
    await expect(page.getByRole('heading', { name: 'New Invoice' })).toHaveCount(0);
});

test('SEC-04 customer is denied the staff payment form', async ({ page, request }) => {
    const invoice = await seedInvoice(request);
    await signIn(page, 'customer');
    const response = await page.goto(`/${company}/modals/documents/${invoice.id}/transactions/create`, { waitUntil: 'domcontentloaded' });
    expect(response.status() === 403 || !new URL(page.url()).pathname.includes('/modals/documents/')).toBe(true);
    await expect(page.locator('#form-transaction')).toHaveCount(0);
});
