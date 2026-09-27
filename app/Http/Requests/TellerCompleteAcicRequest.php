<?php

namespace App\Http\Requests;

/**
 * The accepting teller's **Action → Completed**. Nothing to fill in: who received each cheque
 * forwarded to a payee was recorded when it was forwarded.
 */
class TellerCompleteAcicRequest extends AcicTellerStepRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function stepRules(): array
    {
        return [];
    }
}
