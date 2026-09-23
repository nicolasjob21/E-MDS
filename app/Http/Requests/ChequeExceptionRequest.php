<?php

namespace App\Http\Requests;

/**
 * The three ways out of the flow — RTS, Cancel and Void. Which of them a cheque may take is
 * the status's call; all they need here is a reason, which is never optional.
 */
class ChequeExceptionRequest extends ChequeStepRequest
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
        return ['reason.required' => 'Give the reason for this action.'];
    }
}
