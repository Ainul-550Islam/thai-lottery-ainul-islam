import http from 'k6/http';
import { check, sleep } from 'k6';
import crypto from 'k6/crypto';

export const options = {
    scenarios: {
        order_burst: {
            executor: 'ramping-arrival-rate',
            startRate: 500,
            timeUnit: '1s',
            preAllocatedVUs: 200,
            maxVUs: 1000,
            stages: [
                { duration: '30s', target: 2000 },  // Ramp to 2,000 req/sec
                { duration: '1m', target: 5000 },   // Ramp to 5,000 req/sec
                { duration: '2m', target: 10000 },  // Peak 10,000 req/sec
                { duration: '30s', target: 0 },      // Ramp down
            ],
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<150', 'p(99)<300'], // 95% under 150ms, 99% under 300ms
        http_req_failed: ['rate<0.001'],               // Less than 0.1% failure rate
    },
};

const BASE_URL = __ENV.API_BASE_URL || 'https://api.thai-lottery.internal';
const HMAC_SECRET = __ENV.HMAC_SECRET || 'test-client-secret-key-2026';

function generateUuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        const r = (Math.random() * 16) | 0,
            v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

export default function () {
    const userId = `usr_bench_${Math.floor(Math.random() * 10000)}`;
    const idempotencyKey = `ORD-${generateUuid()}`;
    const timestamp = Math.floor(Date.now() / 1000).toString();

    const payload = JSON.stringify({
        idempotency_key: idempotencyKey,
        draw_id: 'GLO-20261016-01',
        agent_id: 'agt_bench_master_01',
        entries: [
            {
                number: String(Math.floor(Math.random() * 1000000)).padStart(6, '0'),
                bet_type: '6D_DIRECT',
                amount: '100.0000',
            },
            {
                number: String(Math.floor(Math.random() * 1000)).padStart(3, '0'),
                bet_type: '3D_TOP',
                amount: '50.0000',
            },
        ],
    });

    const signaturePayload = `${timestamp}.${payload}`;
    const signature = crypto.hmac('sha256', HMAC_SECRET, signaturePayload, 'hex');

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'X-Idempotency-Key': idempotencyKey,
            'X-Auth-Timestamp': timestamp,
            'X-Signature': signature,
            'Authorization': `Bearer bench-token-${userId}`,
            'User-Agent': 'ThaiLotteryClient/2.4 (iOS; Mobile)',
        },
    };

    const res = http.post(`${BASE_URL}/api/v1/orders/place`, payload, params);

    check(res, {
        'status is 200 or 201': (r) => r.status === 200 || r.status === 201,
        'has order_id': (r) => r.json('data.order_id') !== undefined,
        'ledger transaction recorded': (r) => r.json('data.ledger_transaction_id') !== undefined,
    });

    sleep(0.1);
}
