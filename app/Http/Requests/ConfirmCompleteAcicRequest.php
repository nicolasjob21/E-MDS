<?php

namespace App\Http\Requests;

/**
 * **Confirm and Complete** — lodging the ACIC with Land Bank and closing it in one step.
 *
 * All it asks for is when the ACIC went over the counter, and an optional note. How that time
 * sits against the ACIC's own history is checked in the service, where the record is locked.
 */
class ConfirmCompleteAcicRequest extends AcicTellerStepRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function stepRules(): array
    {
        return [
            'forwarded_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'forwarded_at.required' => 'Enter the date and time the ACIC was forwarded to Land Bank.',
        ];
    }
}
