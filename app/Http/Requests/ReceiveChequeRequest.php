<?php

namespace App\Http\Requests;

/**
 * Step 3 — the signed cheque is back. Recording it carries the cheque straight on to For ACIC.
 */
class ReceiveChequeRequest extends ChequeStepRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function stepRules(): array
    {
        return [
            // Defaults to the signed-in user in the service, but stays editable.
            'received_by_name' => ['nullable', 'string', 'max:255'],
            'date_received' => ['required', 'date'],
            'from_unit_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['date_received.required' => 'Enter the date the signed cheque came back.'];
    }
}
