<?php

namespace App\Http\Requests;

/**
 * Two of the ways out of the flow — RTS and Cancel. Which of them a cheque may take is the
 * status's call; all they need here is a reason, which is never optional. (Spoil, the third,
 * has its own request: it also names the replacement number.)
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
