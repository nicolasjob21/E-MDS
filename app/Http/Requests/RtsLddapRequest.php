<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Return a record to sender. The route is admin-only. Every field is required — the comment
 * is the reason the sender needs, and each RTS is its own history entry.
 */
class RtsLddapRequest extends FormRequest
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
        return [
            'received_on' => ['required', 'date'],
            'received_by' => ['required', 'string', 'max:255'],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'rts_date' => ['required', 'date'],
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'received_on.required' => 'Enter the date received.',
            'received_by.required' => 'Say who received it.',
            'unit_id.required' => 'Choose the RTS unit.',
            'unit_id.exists' => 'Choose a unit from the list.',
            'rts_date.required' => 'Enter the RTS date.',
            'note.required' => 'Say why the record is being returned to sender.',
            'note.min' => 'Give a little more detail in the comment.',
        ];
    }
}
