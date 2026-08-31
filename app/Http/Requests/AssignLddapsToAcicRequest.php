<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class AssignLddapsToAcicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [UserRole::Admin, UserRole::Staff], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lddap_ids' => ['required', 'array', 'min:1', 'max:500'],
            'lddap_ids.*' => ['integer', 'exists:lddaps,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lddap_ids.required' => 'Select at least one LDDAP record to put on this ACIC.',
            'lddap_ids.*.exists' => 'One or more of the selected LDDAP records no longer exists.',
        ];
    }
}
