<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Forwarded to Land Bank" stops being a resting state.
 *
 * Lodging an ACIC with the bank and closing it are one step — **Confirm and Complete** — which
 * records when it went over the counter (`forwarded_to_land_bank_at`, kept) and completes it.
 * Anything sitting on the old status is therefore completed.
 *
 * The status values are literals on purpose: this migration's behaviour must not move when the
 * enums it once named are reworked.
 *
 * @return array{acics: int, cheques: int, lddaps: int}
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->collapse();
    }

    /** Public and idempotent, so the suite can exercise it directly. */
    public function collapse(): array
    {
        return [
            'acics' => DB::table('acics')->where('teller_status', 'forwarded_to_land_bank')
                ->update(['teller_status' => 'completed']),
            'cheques' => DB::table('cheques')->where('status', 'forwarded_to_land_bank')
                ->update(['status' => 'completed']),
            'lddaps' => DB::table('lddaps')->where('status', 'forwarded_to_land_bank')
                ->update(['status' => 'completed']),
        ];
    }

    public function down(): void
    {
        // The two states cannot be told apart once merged, so this does not split them again.
    }
};
