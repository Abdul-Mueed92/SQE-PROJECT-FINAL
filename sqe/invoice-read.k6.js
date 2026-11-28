import http from 'k6/http';
import { check, sleep } from 'k6';
import encoding from 'k6/encoding';

const users = Number(__ENV.SQE_USERS);
const url = __ENV.SQE_URL;
const invoice = Number(__ENV.SQE_INVOICE);
if (![1, 5].includes(users) || !/^http:\/\/127\.0\.0\.1:\d+$/.test(url) || !Number.isInteger(invoice) || invoice < 1) {
    throw new Error('Set SQE_USERS to 1 or 5 and supply a synthetic invoice on the loopback test server.');
}

export const options = {
    scenarios: {
        warm_up: { executor: 'constant-vus', vus: users, duration: '15s', gracefulStop: '0s', tags: { phase: 'warmup' } },
        steady: { executor: 'constant-vus', vus: users, startTime: '15s', duration: '65s', tags: { phase: 'measurement' } },
    },
    thresholds: {
        'http_req_duration{phase:measurement}': [`p(95)<=${users === 1 ? 350 : 1200}`],
        'checks{phase:measurement}': ['rate>0.99'],
        'http_reqs{phase:measurement}': ['count>0'],
    },
    summaryTrendStats: ['count', 'min', 'med', 'p(95)', 'max'],
};

export default function () {
    const response = http.get(`${url}/api/documents/${invoice}?search=type%3Ainvoice`, {
        headers: { Accept: 'application/json', Authorization: `Basic ${encoding.b64encode('test@company.com:123456')}` },
        timeout: '5s',
    });
    check(response, { 'invoice returned': (res) => res.status === 200 && res.json('data.id') === invoice });
    sleep(0.2);
}

export function handleSummary(data) {
    const measured = data.metrics['http_req_duration{phase:measurement}'].values;
    const attemptedRequests = data.metrics['http_reqs{phase:measurement}'].values.count;
    const checks = data.metrics['checks{phase:measurement}'].values;
    const completedRequests = checks.passes;
    const failures = checks.fails;
    const summary = {
        users,
        measurementSeconds: 65,
        p95Ms: measured['p(95)'],
        attemptedRequests,
        completedRequests,
        requestsPerSecond: completedRequests / 65,
        unexpectedFailurePercent: 100 * failures / (completedRequests + failures),
        rawK6Summary: data,
    };
    const json = JSON.stringify(summary, null, 2);
    return { [__ENV.SQE_K6_SUMMARY]: `${json}\n`, stdout: `${json}\n` };
}
