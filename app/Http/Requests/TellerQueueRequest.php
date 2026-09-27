<?php

namespace App\Http\Requests;

use App\Enums\AcicTellerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The teller's ACIC table (the Deposit Queue): `type`, the forwarded `from` / `to` dates,
 * `search` (as the ACIC table) and `status` (one teller status, or all). They combine, and only
 * narrow what the viewer already sees — Pending is everyone's, the rest the viewer's own.
 */
class TellerQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', Rule::in(['cheque', 'lddap'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['all', ...array_column(AcicTellerStatus::cases(), 'value')])],
        ];
    }

    public function searchTerm(): ?string
    {
        $term = trim((string) $this->validated('search', ''));

        return $term === '' ? null : $term;
    }

    public function status(): ?AcicTellerStatus
    {
        return AcicTellerStatus::tryFrom((string) $this->validated('status', 'all'));
    }
}
