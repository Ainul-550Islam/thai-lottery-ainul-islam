import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    scenarios: {
        bot_scraping_and_abuse: {
            executor: 'ramping-vus',
            startVUs: 10,
            stages: [
                { duration: '20s', target: 200 },
                { duration: '40s', target: 500 },
                { duration: '20s', target: 0 },
            ],
        },
    },
    thresholds: {
        // We expect the bot protection middleware to block at least 95% of these malicious requests
        'http_req_failed{status:403}': ['rate>0.95'],
    },
};

const BASE_URL = __ENV.API_BASE_URL || 'https://api.thai-lottery.internal';

const MALICIOUS_USER_AGENTS = [
    'sqlmap/1.6.12#stable',
    'Mozilla/5.0 (HeadlessChrome/112.0.5615.121)',
    'python-requests/2.28.1',
    'Go-http-client/1.1',
    'curl/7.88.1',
];

export default function () {
    const maliciousUa = MALICIOUS_USER_AGENTS[Math.floor(Math.random() * MALICIOUS_USER_AGENTS.length)];

    // Superhuman form submission timing (only 120ms since render)
    const payload = JSON.stringify({
        username: `target_admin_${Math.floor(Math.random() * 100)}`,
        password: 'Password123!',
        _form_rendered_at: (Date.now() / 1000 - 0.12).toString(),
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'User-Agent': maliciousUa,
            'X-Forwarded-For': `198.51.100.${Math.floor(Math.random() * 254) + 1}`,
        },
    };

    const res = http.post(`${BASE_URL}/api/v1/auth/login`, payload, params);

    check(res, {
        'blocked by BotDetectionMiddleware (403)': (r) => r.status === 403,
        'error_code is AUTOMATED_REQUEST_BLOCKED': (r) => r.json('error_code') === 'AUTOMATED_REQUEST_BLOCKED',
    });

    sleep(0.01); // High burst frequency
}
