import http from 'k6/http';
import { check, sleep } from 'k6';
import crypto from 'k6/crypto';

export const options = {
    scenarios: {
        webhook_burst: {
            executor: 'constant-arrival-rate',
            rate: 2500, // 2,500 bank webhooks / second
            timeUnit: '1s',
            duration: '1m',
            preAllocatedVUs: 150,
            maxVUs: 500,
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<80', 'p(99)<150'],
        http_req_failed: ['rate<0.0001'],
    },
};

const BASE_URL = __ENV.API_BASE_URL || 'https://api.thai-lottery.internal';
const SCB_WEBHOOK_SECRET = __ENV.SCB_WEBHOOK_SECRET || 'test-secret-key-123';

export default function () {
    const txId = `SCB-TX-${Math.floor(Math.random() * 1000000000)}`;
    const userId = `usr_bench_${Math.floor(Math.random() * 5000)}`;
    const amount = (Math.floor(Math.random() * 500) + 100).toFixed(2);

    const payload = JSON.stringify({
        transactionId: txId,
        billerId: '0105558099881',
        amount: parseFloat(amount),
        billPaymentRef1: userId,
        billPaymentRef2: 'LOTTO-DEP',
    });

    const signature = crypto.hmac('sha256', SCB_WEBHOOK_SECRET, payload, 'hex');

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'X-SCB-Signature': signature,
            'X-Forwarded-For': '10.0.1.50', // Simulated trusted internal load balancer IP
        },
    };

    const res = http.post(`${BASE_URL}/api/v1/webhooks/payment/scb`, payload, params);

    check(res, {
        'status is 200': (r) => r.status === 200,
        'resCode is 00': (r) => r.json('resCode') === '00',
        'has transactionId': (r) => r.json('transactionId') === txId,
    });

    sleep(0.05);
}
