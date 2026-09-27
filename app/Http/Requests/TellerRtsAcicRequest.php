<?php

namespace App\Http\Requests;

use App\Services\AcicTellerService;
use Illuminate\Validation\Rule;

/**
 * The accepting teller's **Action → RTS**: a required reason, and a status for every check
 * that was out, keyed "cheque:ID" / "lddap:ID" (Completed, Returned, Cancelled, or Stale).
 */
class TellerRtsAcicRequest extends AcicTellerStepRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function stepRules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'outcomes' => ['required', 'array', 'min:1', 'max:500'],
            'outcomes.*' => ['required', 'string', Rule::in(AcicTellerService::RTS_OUTCOMES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give the reason for the RTS.',
            'outcomes.required' => 'Choose a status for each check.',
            'outcomes.*.in' => 'Choose Completed, Returned, Cancelled or Stale.',
        ];
    }

    /** @return array<string, string> */
    public function outcomes(): array
    {
        return (array) $this->validated('outcomes');
    }
}
