import os
os.chdir('/home/user/thai-lottery-ainul-islam')

def rd(p):
    return open(p, encoding='utf-8').read()

def wr(p, s):
    open(p, 'w', encoding='utf-8').write(s)

def rep(p, old, new):
    s = rd(p)
    assert s.count(old) == 1, (p, s.count(old), old[:70])
    wr(p, s.replace(old, new))

# CSS: Tailwind v3 directives (no v4-only at-rules are used in the project)
rep('resources/css/app.css', "@import 'tailwindcss';",
    "@tailwind base;\n@tailwind components;\n@tailwind utilities;")

# User model
U = 'app/Models/User.php'
rep(U, "        'nationality',\n    ];",
    "        'nationality',\n        // Optional registration identity fields (migration 2026_10_10_000100).\n        // national_id is written ONLY through RegistrationService, which also\n        // computes national_id_hash.\n        'national_id',\n        'wechat_id',\n    ];")
rep(U, "    protected $hidden = [\n        'password',\n        'remember_token',\n    ];",
    "    protected $hidden = [\n        'password',\n        'remember_token',\n        'national_id',\n        'national_id_hash',\n    ];")
rep(U, "            'preferences' => 'array',\n        ];",
    "            'preferences' => 'array',\n            // IC / national ID: encrypted at rest with the app key.\n            'national_id' => 'encrypted',\n        ];")

# Stats service: live presence metric, computed outside the cache
G = 'app/Services/Lottery/GloPublicStatsService.php'
s = rd(G)
if 'activeMembersNow' not in s:
    doc_start = s.index("    /**\n     * @return array{")
    doc_end = s.index("    public function publicStats(): array\n")
    doc = s[doc_start:doc_end]
    s = s[:doc_start] + '''    /** Window, in minutes, in which a member's last activity counts as "online". */
    public const ACTIVE_WINDOW_MINUTES = 10;

    /**
     * Session-based live count: members (not soft-deleted) whose last
     * authenticated request falls inside the activity window. Tracked by
     * App\\Http\\Middleware\\TrackMemberPresence, at most once a minute per member.
     */
    public function activeMembersNow(): int
    {
        return (int) User::query()
            ->whereNull('deleted_at')
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subMinutes(self::ACTIVE_WINDOW_MINUTES))
            ->count();
    }

''' + doc + s[doc_end:]
    wr(G, s)
    rep(G, "            return $payload;\n        } catch (\\Throwable $e) {", '''            // The live presence metric is NOT cached: it is a real COUNT taken per request,
            // so the "online" number reflects the last few minutes, not the cache TTL.
            if (($payload['status'] ?? null) === 'VERIFIED') {
                $payload['metrics'][] = [
                    'key' => 'active_members',
                    'label' => 'Members active in the last '.self::ACTIVE_WINDOW_MINUTES.' minutes',
                    'value' => (string) $this->activeMembersNow(),
                    'available' => true,
                ];
            }

            return $payload;
        } catch (\\Throwable $e) {''')

# Translation keys for the optional fields
en_keys = """    'register_section_optional' => 'Optional Details',
    'register_optional_hint' => 'These fields are optional. Leave them blank if you do not want to provide them.',
    'register_national_id' => 'IC / National ID Number (optional)',
    'register_national_id_hint' => 'Letters and digits only. Stored encrypted; only the last four characters are ever shown.',
    'register_wechat_id' => 'WeChat ID (optional)',
    'register_error_national_id_format' => 'Enter 6 to 32 letters or digits for the ID number.',
    'register_error_national_id_taken' => 'This ID number is already registered to another account.',
"""
th_keys = """    'register_section_optional' => 'ข้อมูลเพิ่มเติม (ไม่บังคับ)',
    'register_optional_hint' => 'ช่องนี้ไม่บังคับ หากไม่ต้องการให้ข้อมูลสามารถเว้นว่างได้',
    'register_national_id' => 'เลขบัตรประจำตัวประชาชน (ไม่บังคับ)',
    'register_national_id_hint' => 'ใช้ได้เฉพาะตัวอักษรและตัวเลข ข้อมูลถูกเข้ารหัสและแสดงเพียง 4 ตัวท้ายเท่านั้น',
    'register_wechat_id' => 'WeChat ID (ไม่บังคับ)',
    'register_error_national_id_format' => 'กรอกเลขบัตร 6 ถึง 32 ตัวอักษรหรือตัวเลข',
    'register_error_national_id_taken' => 'เลขบัตรนี้ถูกลงทะเบียนกับบัญชีอื่นแล้ว',
"""
if 'register_national_id' not in rd('lang/en/public_pages.php'):
    rep('lang/en/public_pages.php', "    'register_submit' => 'Register',", en_keys + "    'register_submit' => 'Register',")
if 'register_national_id' not in rd('lang/th/public_pages.php'):
    rep('lang/th/public_pages.php', "    'register_submit' => 'สมัครสมาชิก',", th_keys + "    'register_submit' => 'สมัครสมาชิก',")

# Registration form fieldset before the Terms block
B = 'resources/views/auth/member-register.blade.php'
if 'name="national_id"' not in rd(B):
    rep(B, "            {{-- ================= Terms ================= --}}", '''            {{-- ================= Optional identity ================= --}}
            <fieldset class="space-y-4">
                <legend class="text-sm font-semibold text-slate-200">{{ __('public_pages.register_section_optional') }}</legend>
                <p class="text-xs text-slate-400">{{ __('public_pages.register_optional_hint') }}</p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="national_id" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.register_national_id') }}
                        </label>
                        <input id="national_id" name="national_id" type="text" maxlength="40" value="{{ old('national_id') }}"
                               autocomplete="off" aria-describedby="national_id_hint" @if ($errors->has('national_id')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('national_id') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}">
                        <p id="national_id_hint" class="mt-1 text-xs text-slate-500">{{ __('public_pages.register_national_id_hint') }}</p>
                        @if ($errors->has('national_id'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('national_id') }}</p>
                        @endif
                    </div>
                    <div>
                        <label for="wechat_id" class="block text-sm font-medium text-slate-300 mb-1">
                            {{ __('public_pages.register_wechat_id') }}
                        </label>
                        <input id="wechat_id" name="wechat_id" type="text" maxlength="20" value="{{ old('wechat_id') }}"
                               autocomplete="off" @if ($errors->has('wechat_id')) aria-invalid="true" @endif
                               class="block w-full px-4 py-3 border rounded-xl sm:text-sm {{ $errors->has('wechat_id') ? 'border-rose-600 bg-rose-950/30' : 'border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500' }}">
                        @if ($errors->has('wechat_id'))
                            <p class="mt-1 text-xs text-rose-300" role="alert">{{ $errors->first('wechat_id') }}</p>
                        @endif
                    </div>
                </div>
            </fieldset>

            {{-- ================= Terms ================= --}}''')

# HomeLocalizationTest: the Thai copy no longer carries government-lottery branding
T = 'tests/Feature/Home/HomeLocalizationTest.php'
if "สลากกินแบ่งรัฐบาล" in rd(T):
    rep(T, "        $response->assertSee('สลากกินแบ่งรัฐบาล', false);",
        "        // The Thai home copy was made neutral (no government-lottery branding);\n        // assert on the current Thai hero title rather than the removed wording.\n        $response->assertSee('ผลสลากกินแบ่งและการตรวจรางวัล', false);")
print('applied')
