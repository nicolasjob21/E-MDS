<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "ACIC Assigned" is called **Approved** — putting a cheque on an ACIC is what signs it off,
 * so that is the name the office uses for it.
 *
 * The status keeps its place in the flow (For ACIC → Approved → released or forwarded); only
 * its name changes, here and in the trail behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('acic_assigned', 'approved');
    }

    public function down(): void
    {
        $this->rename('approved', 'acic_assigned');
    }

    /**
     * Move every record and every history row from one status value to the other.
     *
     * Public and idempotent so the test suite can exercise it directly.
     *
     * @return array{cheques: int, history_to: int, history_from: int}
     */
    public function rename(string $from, string $to): array
    {
        return [
            'cheques' => DB::table('cheques')->where('status', $from)->update(['status' => $to]),
            'history_to' => DB::table('cheque_status_history')->where('to_status', $from)->update(['to_status' => $to]),
            'history_from' => DB::table('cheque_status_history')->where('from_status', $from)->update(['from_status' => $to]),
        ];
    }
};
