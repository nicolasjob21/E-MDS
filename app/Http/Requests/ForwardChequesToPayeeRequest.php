<?php

namespace App\Http\Requests;

use App\Support\PcgUnits;
use App\Support\Validity;
use Illuminate\Validation\Rule;

/**
 * The accepting teller's **Forward to Payee**: the cheques ticked (one, several or all), and who
 * received them, when (not in the future) and their unit (the shared PCG unit list) — once for
 * the whole batch. Which cheques may go, and by whom, is the service's call under the lock.
 */
class ForwardChequesToPayeeRequest extends AcicTellerStepRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function stepRules(): array
    {
        return [
            'cheque_ids' => ['required', 'array', 'min:1', 'max:500'],
            'cheque_ids.*' => ['integer', Rule::exists('cheques', 'id')],
            'received_by' => ['required', 'string', 'min:2', 'max:255'],
            // Today in Manila — the app's own clock may still be on yesterday, or already on tomorrow.
            'date_received' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.Validity::today()->toDateString()],
            'unit' => ['required', 'string', PcgUnits::rule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cheque_ids.required' => 'Tick at least one cheque.',
            'cheque_ids.min' => 'Tick at least one cheque.',
            'received_by.required' => 'Enter who received the cheque.',
            'date_received.required' => 'Enter the date received.',
            'date_received.before_or_equal' => 'The date received cannot be in the future.',
            'unit.required' => 'Choose the unit.',
            'unit.in' => 'Choose a unit from the list.',
        ];
    }
}
