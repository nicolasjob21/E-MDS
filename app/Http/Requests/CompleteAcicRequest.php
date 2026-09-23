<?php

namespace App\Http\Requests;

/** The bank credited the ACIC. Final — so the confirmation number is not optional. */
class CompleteAcicRequest extends AcicTellerStepRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function stepRules(): array
    {
        return [
            // When the ACIC went over the counter. Only needed when it was not lodged
            // through the separate Forward to Land Bank step.
            'handed_to_bank_at' => ['nullable', 'date'],
            'credited_at' => ['nullable', 'date'],
            'bank_confirmation_no' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['bank_confirmation_no.required' => 'Enter the bank confirmation or reference number.'];
    }
}
