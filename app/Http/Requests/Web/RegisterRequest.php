<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Rules\StrongPasswordRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates web member registration requests.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $minAgeYears = 18;

        return [
            'referral_id' => ['required', 'string', 'min:3', 'max:64'],
            'mobile' => [
                'required',
                'string',
                'min:6',
                'max:20',
                'regex:/^(?=(?:\D*\d){6})[0-9][0-9\s\-().]{5,24}$/',
                Rule::unique('users', 'phone'),
            ],
            'password' => ['required', 'string', 'max:255', new StrongPasswordRule],
            'password_confirmation' => ['required', 'string', 'same:password'],
            'first_name' => ['required', 'string', 'min:1', 'max:100', 'regex:/^[\p{L}\p{M}\'.\- ]+$/u'],
            'last_name' => ['required', 'string', 'min:1', 'max:100', 'regex:/^[\p{L}\p{M}\'.\- ]+$/u'],
            'gender' => ['required', 'string', Rule::in(['male', 'female', 'unspecified'])],
            'city' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\p{L}\p{M}\'.\- ]+$/u'],
            'country' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\p{L}\p{M}\'.\- ]+$/u'],
            'nationality' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\p{L}\p{M}\'.\- ]+$/u'],
            'email' => [
                'required',
                'string',
                'email:filter',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'date_of_birth' => [
                'required',
                'date',
                'after:1900-01-01',
                'before:'.now()->subYears($minAgeYears)->toDateString(),
            ],
            'terms' => ['required', 'accepted'],
        ];
    }
}
