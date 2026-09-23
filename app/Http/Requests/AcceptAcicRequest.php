<?php

namespace App\Http\Requests;

/** A teller claiming a Pending ACIC. Nothing to fill in — the claim itself is the step. */
class AcceptAcicRequest extends AcicTellerStepRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function stepRules(): array
    {
        return [];
    }
}
