<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLddapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is gated by the `admin` middleware.
    }

    /**
     * An admin correcting the details directly. The reason is **required**: a change that takes
     * effect immediately, with no second pair of eyes, has to say why it was made.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lddap_no' => ['required', 'string', 'max:100'],
            'obj_no' => ['nullable', 'string', 'max:100'],
            'payee_name' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lddap_no.required' => 'Enter the LDDAP number.',
            'amount.required' => 'Enter the amount.',
            'reason.required' => 'Say why you are changing these details.',
            'reason.min' => 'Give a little more detail in the reason.',
        ];
    }
}
