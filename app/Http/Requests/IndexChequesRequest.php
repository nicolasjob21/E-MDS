<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // The validity/disposition tab: all · valid · released · for_deposit ·
            // expiring · deposited · stale.
            'tab' => ['nullable', 'string', Rule::in(self::TABS)],
            // "expiry" puts the cheques closest to going stale first.
            'sort' => ['nullable', 'string', Rule::in(['number', 'expiry'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** The tabs the cheque page offers, in the order it shows them. */
    public const TABS = ['all', 'valid', 'released', 'for_deposit', 'expiring', 'deposited', 'stale'];

    /** The chosen tab; "all" when none or an unknown one was asked for. */
    public function tab(): string
    {
        $tab = (string) $this->input('tab', 'all');

        return in_array($tab, self::TABS, true) ? $tab : 'all';
    }

    public function sortsByExpiry(): bool
    {
        return $this->input('sort') === 'expiry';
    }

    /** The trimmed search term, or null when nothing was searched for. */
    public function searchTerm(): ?string
    {
        $search = trim((string) $this->input('search', ''));

        return $search === '' ? null : $search;
    }
}
