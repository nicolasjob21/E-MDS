<?php

namespace App\Http\Requests;

use App\Support\PcgUnits;

/**
 * Edit a cheque's details — allowed only while it has no status yet, or is For Compliance (the
 * service enforces that). The number is never part of it.
 */
class UpdateChequeDetailsRequest extends ChequePrepareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'payee_name' => ['required', 'string', 'max:255'],
            'account_no' => ['nullable', 'string', 'max:255'],
            'unit_name' => ['nullable', 'string', PcgUnits::rule()],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'cheque_date' => ['required', 'date'],
        ];
    }
}
