<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Payment\PayoutTransferAccess;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ReviewPlayerPrizeClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(PayoutTransferAccess::class)->authorize($this->user(), PayoutTransferAccess::CLAIM_REVIEW);

        return true;
    }

    public function rules(): array
    {
        return ['decision' => ['required', 'in:approved,rejected'], 'original_facts_verified' => ['required', 'accepted'],
            'note' => ['required', 'string', 'min:15', 'max:1000', 'not_regex:/[\x00-\x1f\x7f]/'],
            'actor_id' => ['prohibited'], 'claimant_user_id' => ['prohibited'], 'status' => ['prohibited'],
            'amount' => ['prohibited'], 'currency' => ['prohibited'], 'beneficiary' => ['prohibited'], 'facts_hash' => ['prohibited']];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException($this->expectsJson()
            ? response()->json(['code' => 'player_claim_review_validation_failed'], 422)->header('Cache-Control', 'private, no-store')
            : redirect()->back()->withErrors(['claim' => 'Invalid claim review. No sensitive input was retained.']));
    }
}
