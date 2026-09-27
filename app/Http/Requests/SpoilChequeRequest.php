<?php

namespace App\Http\Requests;

/**
 * Mark an Approved cheque Spoiled. The reason is required. `replacement_number` is the next
 * available number the dialog showed; if another user has taken it since, the step is refused
 * rather than silently moving the payment to a different number.
 */
class SpoilChequeRequest extends ChequeStepRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function stepRules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'replacement_number' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required' => 'Give the reason the cheque is spoiled.'];
    }
}
