<?php

namespace App\Http\Requests;

/** Lodging an ACIC with Land Bank — the first time, or again after a return. */
class ForwardToLandBankRequest extends AcicTellerStepRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function stepRules(): array
    {
        return [
            // Defaults to now in the service; how it sits against the accepted time is
            // checked there, where the ACIC is in hand.
            'forwarded_at' => ['nullable', 'date'],
            'transmittal_no' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
