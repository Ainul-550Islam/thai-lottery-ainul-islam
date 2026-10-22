<?php

declare(strict_types=1);

namespace Tests\Feature\Locale;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

final class BanglaPlayerTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_dashboard_renders_bangla_player_copy(): void
    {
        $user = User::factory()->create(['name' => 'Test Member']);

        $this->actingAs($user)
            ->get(route('player.dashboard').'?lang=bn')
            ->assertOk()
            ->assertSee('খেলোয়াড় ড্যাশবোর্ড')
            ->assertSee('ব্যবহারযোগ্য ব্যালেন্স')
            ->assertSee('আমার সাম্প্রতিক বাজি');
    }

    public function test_every_static_player_reference_has_a_bangla_override(): void
    {
        $keys = [];

        foreach ([app_path(), resource_path('views')] as $root) {
            foreach (File::allFiles($root) as $file) {
                $contents = File::get($file->getPathname());
                preg_match_all(
                    '/(?:trans|__|@lang)\(\s*[\'\"]player\.([A-Za-z0-9_.]+)[\'\"]/',
                    $contents,
                    $matches,
                );

                foreach ($matches[1] ?? [] as $key) {
                    if (str_ends_with($key, '_')) {
                        continue;
                    }

                    $keys[$key] = true;
                }
            }
        }

        $this->assertNotEmpty($keys, 'The static player reference scan must find translation keys.');

        foreach (array_keys($keys) as $key) {
            $this->assertTrue(Lang::has('player.'.$key, 'en'), 'Missing English key: '.$key);
            $this->assertTrue(Lang::has('player.'.$key, 'bn'), 'Missing Bangla key: '.$key);

            // Product and service names are intentionally untranslated brands.
            if ($key === 'nav_glo_l6') {
                continue;
            }

            $this->assertNotSame(
                trans('player.'.$key, [], 'en'),
                trans('player.'.$key, [], 'bn'),
                'Bangla must not silently inherit English for player.'.$key,
            );
        }
    }

    public function test_dynamic_player_payment_and_status_catalogs_are_explicit(): void
    {
        foreach ([
            'payment_method_stripe',
            'payment_method_bkash',
            'payment_method_nagad',
            'payment_method_crypto',
            'payment_method_bank_transfer',
            'payment_method_manual',
        ] as $key) {
            $this->assertTrue(Lang::has('player.'.$key, 'bn'), 'Missing Bangla payment label: '.$key);
        }

        foreach ([
            'status_active', 'status_inactive', 'status_suspended', 'status_banned',
            'status_pending_verification', 'status_completed', 'status_confirmed',
            'status_approved', 'status_rejected', 'status_processing', 'status_failed',
            'status_refunded', 'status_scheduled', 'status_open', 'status_settled',
            'status_won', 'status_lost', 'status_cancelled', 'status_pending',
            'status_under_review', 'status_kyc_required',
        ] as $key) {
            $this->assertNotSame(
                trans('player.'.$key, [], 'en'),
                trans('player.'.$key, [], 'bn'),
                'Dynamic player status must be translated: '.$key,
            );
        }
    }
}
