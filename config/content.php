<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content Marketing / Blog (D5)
|--------------------------------------------------------------------------
|
| Configuration only. No query and no rendering decision lives in this file.
|
| A 2026 audit recorded `blog: 0 · cms: 0 · testimonial: 0` on a product whose
| category carries very large organic search volume ("thai lottery result",
| "ตรวจหวย", "lottery 2D 3D tips"). This file is the operator surface for the
| blog that audit said did not exist.
|
| THE BLOG IS ENABLED BY DEFAULT; ARTICLES ARE NOT
| `enabled` gates the routes. It defaults to true because the routes cost
| nothing when there is no content and the alternative — shipping the code with
| the feature switched off — means the first article cannot go live without a
| deployment. What is NOT automatic is publication: an article only appears
| after it has passed through review (see App\Enums\ArticleStatus).
|
*/

return [

    'enabled' => env('CONTENT_BLOG_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Articles per index page. Twelve is a deliberately small number: a blog
    | index is a navigation page, and a reader who scrolls past thirty links
    | has not been helped, they have been given a wall.
    |
    */
    'per_page' => env('CONTENT_BLOG_PER_PAGE', 12),

    /*
    |--------------------------------------------------------------------------
    | The responsible-gambling notice
    |--------------------------------------------------------------------------
    |
    | Appended to EVERY article, in every category, with no configuration to
    | disable it.
    |
    | This is the reason the notice is config and not a translation string: it
    | is not copy someone should be able to edit casually in a language file
    | alongside marketing slogans, and it must be present even for an article
    | whose own body was authored by someone who forgot.
    |
    | Each supported locale has its own entry. A locale with no entry falls
    | back to English rather than rendering nothing — an absent warning is the
    | one outcome that must never happen.
    |
    */
    'responsible_gambling_notice' => [
        'en' => 'Lottery games are games of chance. Play for entertainment, never '
            .'with money you need, and never chase a loss. You can set deposit, '
            .'stake and session limits at any time in your account settings, or '
            .'self-exclude entirely. You must be of legal age in your jurisdiction '
            .'to play.',
        'th' => 'การเล่นหวยเป็นการเสี่ยงโชค โปรดเล่นเพื่อความบันเทิง ไม่ใช้เงินที่จำเป็นต่อ'
            .'การดำรงชีพ และไม่ควรพยายามเอาคืนเมื่อเสีย คุณสามารถกำหนดวงเงินฝาก วงเงินเดิมพัน'
            .'และระยะเวลาการเล่นได้ในตั้งค่าบัญชี หรือขอแยกตัวเองออกจากระบบได้ทุกเมื่อ '
            .'ผู้เล่นต้องมีอายุตามที่กฎหมายกำหนด',
        'bn' => 'লটারি একটি সৌভাগ্যের খেলা। বিনোদনের জন্য খেলুন, প্রয়োজনীয় টাকা দিয়ে '
            .'কখনোই নয়, এবং ক্ষতি উদ্ধারের চেষ্টা করবেন না। আপনি যেকোনো সময় আপনার '
            .'অ্যাকাউন্ট সেটিংসে জমা, বাজি ও সেশনের সীমা নির্ধারণ করতে পারেন, অথবা '
            .'নিজেকে সম্পূর্ণভাবে বাদ দিতে পারেন। খেলার জন্য আপনাকে আপনার অঞ্চলের '
            .'আইনসম্মত বয়সী হতে হবে।',
    ],

    /*
    |--------------------------------------------------------------------------
    | Structured data
    |--------------------------------------------------------------------------
    |
    | Whether articles emit schema.org BlogPosting.
    |
    | On by default and safe to leave on: a node is only emitted when every
    | required property is present, so an article with no author or no
    | publication date emits nothing rather than emitting something invalid.
    |
    */
    'structured_data' => env('CONTENT_BLOG_STRUCTURED_DATA', true),

    /*
    |--------------------------------------------------------------------------
    | Sitemap
    |--------------------------------------------------------------------------
    |
    | Whether published articles are listed in /sitemap.xml, and how important
    | they are declared to be.
    |
    | 0.6 is deliberately below the static pages: a blog article is not the
    | homepage, and claiming otherwise in a sitemap is the kind of signal that
    | is simply discounted.
    |
    | Articles that must not be indexed (per-draw result coverage) are excluded
    | from the sitemap regardless of this setting — see
    | App\Enums\ArticleCategory::shouldIndex().
    |
    */
    'sitemap' => [
        'enabled' => env('CONTENT_BLOG_SITEMAP', true),
        'priority' => '0.6',
        'changefreq' => 'monthly',
    ],
];
