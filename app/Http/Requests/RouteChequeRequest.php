<?php

namespace App\Http\Requests;

/** Step 2 — routing a registered cheque out for signature. */
class RouteChequeRequest extends ChequeStepRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function stepRules(): array
    {
        return [
            'forward_to_name' => ['required', 'string', 'max:255'],
            'forward_unit_name' => ['nullable', 'string', 'max:255'],
            'date_forwarded' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'forward_to_name.required' => 'Enter who the cheque is being routed to for signature.',
            'date_forwarded.required' => 'Enter the date the cheque was forwarded.',
        ];
    }
}
