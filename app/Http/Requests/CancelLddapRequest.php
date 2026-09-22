<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancel a record that is Returned for ACIC. The route is admin-only; Canceled By is the
 * signed-in user, so only the date and the reason come in — and the reason is required.
 */
class CancelLddapRequest extends FormRequest
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
            'date_canceled' => ['nullable', 'date'],
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Give the reason for canceling this record.',
            'note.min' => 'Give a little more detail in the reason.',
        ];
    }
}
