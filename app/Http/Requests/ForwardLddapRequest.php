<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** "Forward" a registered LDDAP: Registered → For Out. Admin and staff. */
class ForwardLddapRequest extends FormRequest
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
            'forward_to' => ['required', 'string', 'max:255'],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'date_forwarded' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'forward_to.required' => 'Say who or where the record is forwarded to.',
            'unit_id.required' => 'Choose the unit.',
            'unit_id.exists' => 'Choose a unit from the list.',
            'date_forwarded.required' => 'Enter the date forwarded.',
        ];
    }
}
