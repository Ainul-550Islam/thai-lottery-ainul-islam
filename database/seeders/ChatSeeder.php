<?php

namespace Database\Seeders;

use App\Models\Chat\ChatCannedResponse;
use Illuminate\Database\Seeder;

/**
 * Seeds the default canned (saved) replies for the live-chat desk.
 *
 * ============================================================================
 * IDEMPOTENT BY KEY
 * ============================================================================
 * Every row is addressed by its stable `key`. A row that already exists is
 * UPDATED in place rather than duplicated, so running this seeder twice — or
 * re-seeding during a deploy — never produces two copies of the same reply
 * and never resurrects one a supervisor disabled (enabled is only written
 * for rows this seeder creates, never flipped back on for existing ones).
 *
 * ============================================================================
 * BILINGUAL BY DESIGN
 * ============================================================================
 * The desk serves a Thai market with English fallback, so each reply ships
 * as an EN row and a TH row keyed `…` and `….th`. The picker resolves a
 * locale, then falls back to EN, which is the same rule the console uses at
 * runtime — seeding both keeps the two in lockstep.
 *
 * ============================================================================
 * PLACEHOLDERS ARE DECLARED, NOT AD-HOC
 * ============================================================================
 * `placeholders` lists exactly the tokens each body may expand. The service
 * only substitutes tokens present here AND supplied by the caller, so a
 * token neither declared nor provided stays literal — never a silent leak of
 * request data into a reply.
 */
class ChatSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->cannedResponses() as $attributes) {
            $this->upsert($attributes);
        }
    }

    /**
     * Insert-or-update one canned response keyed on `key`.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(array $attributes): void
    {
        $existing = ChatCannedResponse::query()
            ->where('key', $attributes['key'])
            ->first();

        if ($existing instanceof ChatCannedResponse) {
            // Refresh content and ordering, but leave `enabled` alone so a
            // supervisor's decision to disable a reply survives a reseed.
            $existing->fill([
                'title' => $attributes['title'],
                'body' => $attributes['body'],
                'category' => $attributes['category'],
                'placeholders' => $attributes['placeholders'],
                'locale' => $attributes['locale'],
                'sort_order' => $attributes['sort_order'],
            ])->save();

            return;
        }

        ChatCannedResponse::query()->create($attributes);
    }

    /**
     * The default reply set.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cannedResponses(): array
    {
        return [
            [
                'key' => 'greeting',
                'title' => 'Greeting',
                'body' => 'Hello {name}, thanks for contacting us. How can I help you today?',
                'category' => 'greeting',
                'placeholders' => ['name'],
                'locale' => 'en',
                'sort_order' => 10,
                'enabled' => true,
            ],
            [
                'key' => 'greeting.th',
                'title' => 'การทักทาย',
                'body' => 'สวัสดีค่ะ/ครับ คุณ {name} ขอบคุณที่ติดต่อเรา วันนี้ให้ช่วยเรื่องอะไรดีคะ/ครับ',
                'category' => 'greeting',
                'placeholders' => ['name'],
                'locale' => 'th',
                'sort_order' => 10,
                'enabled' => true,
            ],
            [
                'key' => 'deposit-check',
                'title' => 'Checking a deposit',
                'body' => 'Thanks {name}. I am checking your deposit now — please allow a few minutes while I confirm it.',
                'category' => 'payment',
                'placeholders' => ['name'],
                'locale' => 'en',
                'sort_order' => 20,
                'enabled' => true,
            ],
            [
                'key' => 'deposit-check.th',
                'title' => 'กำลังตรวจสอบเงินฝาก',
                'body' => 'ขอบคุณค่ะ/ครับ คุณ {name} กำลังตรวจสอบเงินฝากของคุณ โปรดรอสักครู่ระหว่างที่เรายืนยัน',
                'category' => 'payment',
                'placeholders' => ['name'],
                'locale' => 'th',
                'sort_order' => 20,
                'enabled' => true,
            ],
            [
                'key' => 'withdrawal-timeline',
                'title' => 'Withdrawal timeline',
                'body' => 'Your withdrawal request is being processed. It usually completes within the published time window; I will update you here if anything changes.',
                'category' => 'payment',
                'placeholders' => [],
                'locale' => 'en',
                'sort_order' => 30,
                'enabled' => true,
            ],
            [
                'key' => 'withdrawal-timeline.th',
                'title' => 'ระยะเวลาการถอนเงิน',
                'body' => 'คำขอถอนเงินของคุณกำลังดำเนินการ โดยปกติจะเสร็จภายในระยะเวลาที่ประกาศไว้ หากมีความคืบหน้าจะแจ้งให้ทราบที่นี่',
                'category' => 'payment',
                'placeholders' => [],
                'locale' => 'th',
                'sort_order' => 30,
                'enabled' => true,
            ],
            [
                'key' => 'need-screenshot',
                'title' => 'Ask for a screenshot',
                'body' => 'Could you attach a screenshot of what you see? That will help me look into this faster.',
                'category' => 'kyc',
                'placeholders' => [],
                'locale' => 'en',
                'sort_order' => 40,
                'enabled' => true,
            ],
            [
                'key' => 'need-screenshot.th',
                'title' => 'ขอภาพหน้าจอ',
                'body' => 'กรุณาแนบภาพหน้าจอที่คุณเห็น เพื่อช่วยให้เราตรวจสอบได้เร็วขึ้น',
                'category' => 'kyc',
                'placeholders' => [],
                'locale' => 'th',
                'sort_order' => 40,
                'enabled' => true,
            ],
            [
                'key' => 'reference-followup',
                'title' => 'Reference follow-up',
                'body' => 'Your conversation reference is {reference}. Please quote it if you follow up by contact form.',
                'category' => 'closing',
                'placeholders' => ['reference'],
                'locale' => 'en',
                'sort_order' => 50,
                'enabled' => true,
            ],
            [
                'key' => 'reference-followup.th',
                'title' => 'หมายเลขอ้างอิง',
                'body' => 'หมายเลขอ้างอิงการสนทนาของคุณคือ {reference} กรุณาแจ้งหมายเลขนี้หากติดตามผลผ่านแบบฟอร์มติดต่อ',
                'category' => 'closing',
                'placeholders' => ['reference'],
                'locale' => 'th',
                'sort_order' => 50,
                'enabled' => true,
            ],
            [
                'key' => 'closing',
                'title' => 'Closing the chat',
                'body' => 'Is there anything else I can help with? If not, I will close this chat. Thank you!',
                'category' => 'closing',
                'placeholders' => [],
                'locale' => 'en',
                'sort_order' => 60,
                'enabled' => true,
            ],
            [
                'key' => 'closing.th',
                'title' => 'ปิดการสนทนา',
                'body' => 'มีอะไรให้ช่วยเพิ่มเติมอีกไหมคะ/ครับ หากไม่มีเราจะปิดการสนทนานี้ ขอบคุณค่ะ/ครับ',
                'category' => 'closing',
                'placeholders' => [],
                'locale' => 'th',
                'sort_order' => 60,
                'enabled' => true,
            ],
        ];
    }
}
