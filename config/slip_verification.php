<?php

declare(strict_types=1);

$receivingRaw = trim((string) env('SLIP_VERIFICATION_RECEIVING_ACCOUNTS', ''));
$receivingAccounts = [];

if ($receivingRaw !== '') {
    $decoded = json_decode($receivingRaw, true);
    if (is_array($decoded) && array_is_list($decoded)) {
        foreach ($decoded as $account) {
            if (is_array($account) && isset($account['bank'], $account['account_last_digits'], $account['name'])) {
                $receivingAccounts[] = [
                    'bank' => (string) $account['bank'],
                    'account_last_digits' => (string) $account['account_last_digits'],
                    'name' => (string) $account['name'],
                ];
            }
        }
    } else {
        foreach (explode(';', $receivingRaw) as $entry) {
            $parts = array_map('trim', explode(',', $entry, 3));
            if (count($parts) === 3 && $parts[0] !== '' && $parts[1] !== '' && $parts[2] !== '') {
                $receivingAccounts[] = ['bank' => $parts[0], 'account_last_digits' => $parts[1], 'name' => $parts[2]];
            }
        }
    }
}

return [
    'enabled' => (bool) env('SLIP_VERIFICATION_ENABLED', false),
    'provider' => (string) env('SLIP_VERIFICATION_PROVIDER', 'easyslip'),
    'auto_credit' => (bool) env('SLIP_VERIFICATION_AUTO_CREDIT', false),
    'max_age_minutes' => (int) env('SLIP_VERIFICATION_MAX_AGE_MINUTES', 30),
    'receiving_accounts' => $receivingAccounts,
    'providers' => [
        'easyslip' => [
            'base_url' => (string) env('EASYSLIP_BASE_URL', 'https://api.easyslip.com/v2'),
            'verify_path' => (string) env('EASYSLIP_VERIFY_PATH', '/verify/bank'),
            'token' => (string) env('EASYSLIP_TOKEN', ''),
            'timeout' => (int) env('EASYSLIP_TIMEOUT_SECONDS', 12),
        ],
        'slipok' => [
            'base_url' => (string) env('SLIPOK_BASE_URL', 'https://api.slipok.com'),
            'verify_path' => (string) env('SLIPOK_VERIFY_PATH', '/api/line/apikey/{branch_id}'),
            'branch_id' => (string) env('SLIPOK_BRANCH_ID', ''),
            'api_key' => (string) env('SLIPOK_API_KEY', ''),
            'authorization_header' => (string) env('SLIPOK_AUTHORIZATION_HEADER', 'x-authorization'),
            'timeout' => (int) env('SLIPOK_TIMEOUT_SECONDS', 12),
        ],
    ],
];
