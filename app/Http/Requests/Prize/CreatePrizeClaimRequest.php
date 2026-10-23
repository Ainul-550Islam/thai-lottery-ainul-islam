<?php

declare(strict_types=1);

namespace App\Http\Requests\Prize;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class CreatePrizeClaimRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    protected $dontFlash = [
        'ticket_number',
        'verification_code',
        'beneficiary',
        'note',
    ];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bet_id' => ['required', 'integer', 'min:1'],
            'ticket_number' => ['prohibited'],
            'verification_code' => ['prohibited'],
            'claimant_user_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'beneficiary' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bet_id.required' => 'A claim must reference the bet it asserts winnings against.',
            'ticket_number.prohibited' => 'Paper-ticket possession secrets are not accepted on the digital prize-claim endpoint.',
            'verification_code.prohibited' => 'Paper-ticket possession secrets are not accepted on the digital prize-claim endpoint.',
            'claimant_user_id.prohibited' => 'The claimant is always the authenticated account; it may not be asserted by the client.',
            'user_id.prohibited' => 'The claimant is always the authenticated account; it may not be asserted by the client.',
        ];
    }

    public function betId(): int
    {
        return (int) $this->validated('bet_id');
    }

    protected function failedValidation(Validator $validator): void
    {
        if (! $this->expectsJson()) {
            throw new HttpResponseException(
                redirect()->back()->withErrors($validator->errors())
            );
        }

        parent::failedValidation($validator);
    }
}
