<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexChequesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Listing is open to any authenticated user.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // "all" or any ChequeStatus value; anything else is ignored rather than rejected.
            'status' => ['nullable', 'string', 'max:32'],
            // Matches against the cheque number or the ACIC no.
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** The trimmed search term, or null when nothing was searched for. */
    public function searchTerm(): ?string
    {
        $search = trim((string) $this->input('search', ''));

        return $search === '' ? null : $search;
    }
}
