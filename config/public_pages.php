<?php

/*
|--------------------------------------------------------------------------
| Public informational pages (/about, /vision, /terms)
|--------------------------------------------------------------------------
|
| Structure-only configuration. Copy lives in lang/{en,th}/public_pages.php.
| Useful links point only at routes that actually exist on this application —
| never competitor URLs, never raw API endpoints as primary UX.
| No secrets, PII, admin metrics or private inventories in this file.
|
*/

return [

    // Cache buster for public-page composed data (independent of legal version).
    'content_version' => (string) env('PUBLIC_PAGES_CONTENT_VERSION', '3'),

    // Seconds public page data may be cached. Key always includes
    // language + legal version + content version.
    'cache_ttl_seconds' => (int) env('PUBLIC_PAGES_CACHE_TTL', 300),

    // How-it-works steps (About). Keys match lang files; labels localized.
    'how_it_works_steps' => ['choose', 'buy', 'result', 'claim'],

    // Core values (Vision) — this platform's own framework, not a competitor's.
    'core_values' => [
        'transparency',
        'security',
        'accountability',
        'fairness',
        'privacy',
        'reliability',
    ],

    // Governance controls listed on Vision — only controls this codebase
    // actually implements. Never claim absolute security or invulnerability.
    'governance_controls' => [
        'idempotency',
        'transactional_ledger',
        'immutable_audit',
        'source_status_labels',
        'server_authorization',
        'kyc_gates',
        'payout_holds',
        'sales_reconciliation',
    ],

    // Useful links (About): named routes that exist in routes/web.php.
    'useful_links' => [
        ['key' => 'results', 'route' => 'results.index'],
        ['key' => 'ticket_check', 'route' => 'ticket-check'],
        ['key' => 'sales_points', 'route' => 'sales-points'],
        ['key' => 'contact', 'route' => 'contact'],
        ['key' => 'privacy', 'route' => 'privacy'],
        ['key' => 'terms', 'route' => 'terms'],
    ],

    // Operator markets shown under product separation on Terms.
    // These are generic platform markets — NEVER labelled GLO N3.
    'operator_markets' => ['3D', 'TOD', '2D', 'Run'],

    // Responsible-gaming features actually implemented (for Terms reference).
    'responsible_gaming_features' => [
        'self_exclusion',
        'limits',
        'reality_checks',
    ],

    // Seconds legal/informational responses may be publicly cached (guests only).
    'legal_headers_max_age' => (int) env('PUBLIC_PAGES_LEGAL_MAX_AGE', 60),

];
