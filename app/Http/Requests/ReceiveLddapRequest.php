<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** "Receive" a forwarded LDDAP back: For Out → Returned for ACIC. Admin and staff. */
class ReceiveLddapRequest extends FormRequest
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
            // The unit it came back from.
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'date_received' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unit_id.required' => 'Choose the unit it came back from.',
            'unit_id.exists' => 'Choose a unit from the list.',
            'date_received.required' => 'Enter the date received.',
        ];
    }
}
