<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Models\Draw;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Web Bulk Bet Placement Request.
 *
 * BLOCKER CLOSURE — browser revenue path.
 *
 * Three artefacts disagreed about how a browser identifies the draw it is
 * betting on:
 *
 *   - resources/js/lottery/bet-slip.js posts `draw_id` (its own contract
 *     docblock documents `{draw_id, client_key, items}`);
 *   - Api\V1\{BulkBetRequest,PurchaseBetRequest} require `draw_id`;
 *   - Web\BetPurchaseController inline-validated `draw_reference`, and this
 *     FormRequest — which the controller never used — agreed with it.
 *
 * The result was that the same logical purchase returned 201 with a ticket on
 * the API and 422 "The draw reference field is required." in a real browser.
 * Every browser-originated purchase failed.
 *
 * The canonical identifier at the domain boundary is the integer draw id:
 * BulkBetService::purchase() takes `int $drawId`, and that is what both
 * surfaces must hand it. `draw_number` remains the canonical *public* reference
 * (unique index on draws.draw_number) and is still accepted so that any
 * server-rendered form or integration posting a human reference keeps working.
 *
 * This request is now the single validation contract for the web purchase
 * surface; the controller no longer validates inline.
 */
class BetPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Exactly one of the two identifiers is required. Neither is
            // trusted beyond "resolve it to a Draw" — every authorisation,
            // draw-open, limit and balance rule is enforced downstream by
            // BulkBetService, identically to the API path.
            'draw_id' => ['required_without:draw_reference', 'integer', 'min:1'],
            'draw_reference' => ['required_without:draw_id', 'string', 'max:128'],

            'client_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.market' => ['required', 'string', 'max:32'],
            'items.*.number' => ['required', 'string', 'min:1', 'max:6', 'regex:/^[0-9]+$/'],
            'items.*.stake' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('draw_id') && ! $this->filled('draw_reference')) {
                $validator->errors()->add('draw_id', (string) trans('player.draw_selection_invalid'));
            }
        });
    }

    /**
     * Resolve the submitted identifier to the canonical draw.
     *
     * Returns null for an unknown draw so the caller can answer with a safe
     * validation response; no mutation happens on this path.
     */
    public function resolveDraw(): ?Draw
    {
        if ($this->filled('draw_id')) {
            return Draw::query()->whereKey((int) $this->input('draw_id'))->first();
        }

        $reference = trim((string) $this->input('draw_reference'));

        if ($reference === '') {
            return null;
        }

        return Draw::query()->where('draw_number', $reference)->first();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'draw_id.required_without' => (string) trans('player.draw_selection_invalid'),
            'draw_reference.required_without' => (string) trans('player.draw_selection_invalid'),
            'client_key.required' => 'Client idempotency token is required.',
            'items.required' => 'Your bet slip contains no selections.',
            'items.max' => 'A maximum of 50 selections are allowed per slip.',
            'items.*.number.regex' => 'Lottery selections must contain valid numeric digits only.',
        ];
    }
}
