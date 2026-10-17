<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class IssuePosTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'agent_id' => ['required', 'string'],
            'draw_id' => ['required', 'string'],
            'entries' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*.number' => ['required', 'string', 'max:6'],
            'entries.*.bet_type' => ['required', 'string', 'in:6D_OFFICIAL,3D_DIRECT,3D_TOAD,2D_TOP,2D_BOTTOM,RUNNING_TOP,RUNNING_BOTTOM'],
            'entries.*.amount' => ['required', 'numeric', 'min:1.00', 'max:100000.00'],
        ];
    }
}
