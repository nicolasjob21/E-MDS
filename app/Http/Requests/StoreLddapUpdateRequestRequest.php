<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreLddapUpdateRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only staff may request detail corrections.
        return $this->user()?->role === UserRole::Staff;
    }

    /**
     * The correctable details. The check number is not among them: it comes from the LDDAP
     * series and is never edited by hand.
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
            'reason.required' => 'Say what is wrong and why it must change.',
            'reason.min' => 'Give a little more detail in the reason.',
        ];
    }
}
