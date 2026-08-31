<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Services\LddapService;
use Illuminate\Foundation\Http\FormRequest;

class UseChequeForLddapsRequest extends FormRequest
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
            // The number the batch is expected to start at. The service re-derives the real
            // next number under a lock and rejects the request if the two disagree, so a stale
            // preview can never cause a number to be skipped.
            'start_at' => ['required', 'integer', 'min:1', 'max:'.LddapService::MAX_CHECK_NO],

            'rows' => ['required', 'array', 'min:1', 'max:'.LddapService::MAX_BATCH],
            'rows.*.lddap_no' => ['required', 'string', 'max:100'],
            'rows.*.obj_no' => ['nullable', 'string', 'max:100'],
            'rows.*.payee_name' => ['nullable', 'string', 'max:255'],
            'rows.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.required' => 'Add at least one LDDAP record.',
            'rows.min' => 'Add at least one LDDAP record.',
            'rows.max' => 'A single batch can carry at most '.LddapService::MAX_BATCH.' LDDAP records.',
            'rows.*.lddap_no.required' => 'Enter the LDDAP number.',
            'rows.*.amount.required' => 'Enter the amount.',
            'rows.*.amount.min' => 'The amount must be greater than zero.',
        ];
    }
}
