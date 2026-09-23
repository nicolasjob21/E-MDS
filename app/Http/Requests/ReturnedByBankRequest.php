<?php

namespace App\Http\Requests;

/**
 * Recording that Land Bank sent an ACIC back. The records it actually affected may be named;
 * leaving them out means all of them.
 */
class ReturnedByBankRequest extends AcicTellerStepRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function stepRules(): array
    {
        return [
            'returned_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'cheque_ids' => ['nullable', 'array'],
            'cheque_ids.*' => ['integer'],
            'lddap_ids' => ['nullable', 'array'],
            'lddap_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required' => 'Say why the bank returned this ACIC.'];
    }
}
