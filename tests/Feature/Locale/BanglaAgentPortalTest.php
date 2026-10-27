<?php

declare(strict_types=1);

namespace Tests\Feature\Locale;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

final class BanglaAgentPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_agent_portal_renders_bangla_copy(): void
    {
        $user = User::factory()->create();
        Agent::factory()->active()->create(['user_id' => $user->getKey()]);

        $this->actingAs($user)
            ->get('/agent?lang=th')
            ->assertOk()
            ->assertSee('แดชบอร์ดตัวแทน')
            ->assertSee('สรุปค่าคอมมิชชัน');

        $this->actingAs($user)
            ->get('/agent?lang=bn')
            ->assertOk()
            ->assertSee('এজেন্ট ড্যাশবোর্ড')
            ->assertSee('কমিশনের সারাংশ')
            ->assertSee('রেফার করা খেলোয়াড়')
            ->assertSee('খেলোয়াড়ের ব্যক্তিগত ব্যালেন্স')
            ->assertSee('উপলভ্য');
    }

    public function test_every_static_agent_translation_reference_has_a_bangla_value(): void
    {
        $keys = [];

        foreach ([app_path(), resource_path('views')] as $root) {
            foreach (File::allFiles($root) as $file) {
                $contents = File::get($file->getPathname());
                preg_match_all(
                    '/(?:trans|__|@lang)\(\s*[\'\"]agent\.([A-Za-z0-9_.]+)[\'\"]/',
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
            $this->assertTrue(Lang::has('agent.'.$key, 'en'), 'Missing English key: '.$key);
            $this->assertTrue(Lang::has('agent.'.$key, 'bn'), 'Missing Bangla key: '.$key);
            $this->assertNotSame(
                trans('agent.'.$key, [], 'en'),
                trans('agent.'.$key, [], 'bn'),
                'Bangla must not silently inherit English for agent.'.$key,
            );
        }
    }

    public function test_dynamic_agent_states_and_commission_statuses_are_localized(): void
    {
        foreach ([
            'state_available', 'state_no_data', 'value_active', 'value_inactive',
            'value_suspended', 'value_terminated', 'value_banned', 'value_pending_verification',
            'value_accrued', 'value_calculated', 'value_payable', 'value_paid',
            'value_reversed', 'value_cancelled',
        ] as $key) {
            $this->assertNotSame(
                trans('agent.'.$key, [], 'en'),
                trans('agent.'.$key, [], 'bn'),
                'Bangla agent state must not fall back to English: '.$key,
            );
            $this->assertNotSame(
                trans('agent.'.$key, [], 'en'),
                trans('agent.'.$key, [], 'th'),
                'Thai agent state must not fall back to English: '.$key,
            );
        }
    }
}
