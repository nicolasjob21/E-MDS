<?php

namespace App\Http\Requests;

/** The teller hands the ACIC back to the admin, with a reason. */
class ReturnAcicToAdminRequest extends AcicTellerStepRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function stepRules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required' => 'Give the reason for returning this ACIC.'];
    }
}
