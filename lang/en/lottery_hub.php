<?php

declare(strict_types=1);

return [
    'meta_title' => 'Lottery Hub',
    'meta_description' => 'Browse the public lottery result lanes available on ThaiLotto. Result availability is read from each lane and is never replaced with sample values.',
    'eyebrow' => 'Public lottery hub',
    'heading_prefix' => 'Choose your',
    'heading_accent' => 'lottery lane',
    'intro' => 'Explore the result and archive surfaces that are currently configured. Each product keeps its own data, provenance and rules; this hub does not invent prices, schedules or winning numbers.',
    'products_heading' => 'Lottery products',
    'all_products' => 'All products',
    'compare_heading' => 'Compare public lanes',
    'compare_product' => 'Product',
    'compare_data' => 'Data state',
    'compare_result' => 'Result page',
    'product_label' => 'Lottery lane',
    'view_results' => 'View results',
    'buy_or_select' => 'Buy or select',
    'current_result_available' => 'A published result is available from this lane.',
    'current_result_unavailable' => 'No published result is available from this lane right now.',
    'unavailable_heading' => 'Lottery catalogue unavailable',
    'unavailable_body' => 'No enabled public lottery lane is configured. Nothing is displayed as a substitute.',
    'trust_heading' => 'Data and provenance',
    'trust_body' => 'Result cards are backed by the lane-specific public projection. Source state is shown on result pages, and missing data is presented as unavailable rather than estimated.',
    'breadcrumb' => 'Lottery navigation',
    'buy_title' => 'Buy / Ticket Selection',
    'buy_eyebrow' => 'Product purchase surface',
    'buy_meta_description' => ':product purchase and ticket selection surface.',
    'purchase_unavailable_heading' => 'Purchase is not configured for this product lane',
    'purchase_unavailable_body' => 'The repository has a canonical purchase pipeline for operator bets, but it does not currently expose a verified product-specific mapping for this result lane. This page therefore accepts no number, price, discount, wallet instruction or purchase request. No fake success can be shown.',
    'purchase_safety_heading' => 'Purchase safeguards',
    'purchase_safety' => [
        'price' => [
            'heading' => 'Server-authoritative price',
            'body' => 'A purchase surface must obtain price and totals from the server. No browser value is accepted here.',
        ],
        'wallet' => [
            'heading' => 'Wallet and ledger',
            'body' => 'A future lane adapter must reuse the existing lock, reservation, debit and ledger pipeline.',
        ],
        'success' => [
            'heading' => 'No false success',
            'body' => 'Until that adapter exists, this page remains unavailable and makes no purchase claim.',
        ],
    ],
    'back_to_results' => 'Back to results',
    'state' => [
        'RESULT_AVAILABLE' => 'Result available',
        'RESULT_FOUND' => 'Result available',
        'NO_PUBLIC_DATA' => 'No public data',
        'RESULT_NOT_FOUND' => 'No public result',
        'RESULT_UNAVAILABLE' => 'Result unavailable',
        'INVALID_QUERY' => 'Unavailable',
        'UNAVAILABLE' => 'Unavailable',
    ],
    'products' => [
        'national_lottery' => [
            'title' => 'National Lottery',
            'description' => 'Published National Lottery draws, searchable history, year archives and source-backed result details.',
        ],
        'weekly_lottery' => [
            'title' => 'Weekly Lottery',
            'description' => 'Published Weekly Lottery draws with preserved fixed-width values, archives, search and provenance.',
        ],
        'bingo_lottery' => [
            'title' => 'Mega Lottery',
            'description' => 'A separate result lane shown only when its own public route and result data are configured.',
        ],
        'pcso_lottery' => [
            'title' => 'PCSO Lottery',
            'description' => 'A separate result lane shown only when its own public route and result data are configured.',
        ],
    ],
];
