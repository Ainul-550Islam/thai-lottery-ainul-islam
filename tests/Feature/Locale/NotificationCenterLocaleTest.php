<?php

declare(strict_types=1);

namespace Tests\Feature\Locale;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

final class NotificationCenterLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_center_renders_thai_and_bangla(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/notifications?lang=th')
            ->assertOk()
            ->assertSee('ศูนย์การแจ้งเตือน')
            ->assertSee('ไม่มีการแจ้งเตือน')
            ->assertSee('สถานะ');

        $this->actingAs($user)
            ->get('/notifications?lang=bn')
            ->assertOk()
            ->assertSee('বিজ্ঞপ্তি কেন্দ্র')
            ->assertSee('কোনো বিজ্ঞপ্তি নেই')
            ->assertSee('অবস্থা');
    }

    public function test_every_static_notification_reference_has_thai_and_bangla_values(): void
    {
        $keys = [];

        foreach ([app_path(), resource_path('views')] as $root) {
            foreach (File::allFiles($root) as $file) {
                $contents = File::get($file->getPathname());
                preg_match_all(
                    '/(?:trans|__|@lang)\(\s*[\'\"]notifications\.([A-Za-z0-9_.]+)[\'\"]/',
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

        $this->assertNotEmpty($keys);

        foreach (array_keys($keys) as $key) {
            $this->assertTrue(Lang::has('notifications.'.$key, 'en'));
            $this->assertTrue(Lang::has('notifications.'.$key, 'th'));
            $this->assertTrue(Lang::has('notifications.'.$key, 'bn'));
            $this->assertNotSame(trans('notifications.'.$key, [], 'en'), trans('notifications.'.$key, [], 'th'));
            $this->assertNotSame(trans('notifications.'.$key, [], 'en'), trans('notifications.'.$key, [], 'bn'));
        }
    }

    public function test_dynamic_notification_event_and_lifecycle_labels_are_localized(): void
    {
        foreach ([
            'kyc_verified', 'kyc_rejected', 'deposit_confirmed', 'deposit_failed',
            'withdrawal_approved', 'withdrawal_paid', 'withdrawal_rejected', 'bet_placed',
            'bet_won', 'prize_claim_update', 'compliance_hold', 'compliance_released',
            'self_exclusion_started', 'limit_changed', 'session_revoked', 'reality_check',
            'manual', 'glo_result_available', 'dealer_request_update',
        ] as $event) {
            $key = 'event_'.$event;
            $this->assertNotSame(trans('notifications.'.$key, [], 'en'), trans('notifications.'.$key, [], 'th'));
            $this->assertNotSame(trans('notifications.'.$key, [], 'en'), trans('notifications.'.$key, [], 'bn'));
        }

        foreach (['pending', 'queued', 'sent', 'delivered', 'failed', 'suppressed', 'expired'] as $status) {
            $key = 'status_'.$status;
            $this->assertNotSame(trans('notifications.'.$key, [], 'en'), trans('notifications.'.$key, [], 'th'));
            $this->assertNotSame(trans('notifications.'.$key, [], 'en'), trans('notifications.'.$key, [], 'bn'));
        }
    }
}
