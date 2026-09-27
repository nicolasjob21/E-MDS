<?php

namespace App\Http\Requests;

use App\Services\AcicTellerService;
use Illuminate\Validation\Rule;

/** The accepting teller's **Forward**: to Land Bank or to the payee. */
class TellerForwardAcicRequest extends AcicTellerStepRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function stepRules(): array
    {
        return [
            'to' => ['required', 'string', Rule::in(array_keys(AcicTellerService::FORWARD_TO))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['to.required' => 'Choose Forward to LBP or Forward to Payee.', 'to.in' => 'Choose Forward to LBP or Forward to Payee.'];
    }
}
