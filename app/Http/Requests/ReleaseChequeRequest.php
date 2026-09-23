<?php

namespace App\Http\Requests;

/**
 * Branch A — handing a cheque on an ACIC to the payee or their authorised representative.
 *
 * The date's relationship to the cheque is checked in the service, where the cheque itself is
 * in hand and locked.
 */
class ReleaseChequeRequest extends ChequeStepRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function stepRules(): array
    {
        return [
            'received_by_name' => ['required', 'string', 'max:255'],
            'date_received' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'received_by_name.required' => 'Enter the full name of the person who received the cheque.',
            'date_received.required' => 'Enter the date the cheque was received.',
        ];
    }
}
