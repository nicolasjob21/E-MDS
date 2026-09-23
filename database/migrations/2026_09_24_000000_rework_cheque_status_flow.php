<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One ordered status flow for cheques, replacing the two axes that came before it.
 *
 * `status` and `disposition` are folded into a single `status`: where a cheque *is* and how far
 * it has *got* turned out to be the same question once the flow was written down. The old
 * review outcomes retire with it — RTS, Cancel and Void take their place.
 *
 * The teller half of the flow is an **ACIC-level** affair: a whole ACIC is forwarded, claimed
 * and deposited at once, so those columns live on `acics` and every cheque on the ACIC moves
 * with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheque_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cheque_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            /** The named step — registered, routed, received, assigned, released, accepted, … */
            $table->string('action');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            /** The ACIC an ACIC-level step moved this cheque with, if any. */
            $table->foreignId('acic_id')->nullable()->constrained('acics')->nullOnDelete();
            /** The step's own fields — counterparty, unit, dates, reference — as given. */
            $table->json('details')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['cheque_id', 'id']);
        });

        Schema::table('acics', function (Blueprint $table) {
            // Branch B is taken by the ACIC as a whole, not cheque by cheque.
            $table->foreignId('forwarded_to_teller_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('forwarded_to_teller_at')->nullable();
            $table->text('forward_note')->nullable();
            // The first teller to claim it wins; the rest are turned away by name.
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->date('deposit_date')->nullable();
            $table->string('deposit_bank')->nullable();
            $table->string('deposit_reference')->nullable();
            $table->text('deposit_note')->nullable();
            $table->foreignId('returned_to_admin_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_to_admin_at')->nullable();
            $table->text('return_reason')->nullable();
        });

        Schema::table('cheques', function (Blueprint $table) {
            // Step 2 — routed out for signature.
            $table->string('forward_to_name')->nullable();
            $table->string('forward_unit_name')->nullable();
            // Step 3 — signed and back. (`received_by` / `received_at` already exist.)
            $table->string('received_by_name_in')->nullable();
            $table->date('date_received_in')->nullable();
            $table->string('from_unit_name')->nullable();
            // The reason behind an RTS, a cancel or a void.
            $table->text('exception_reason')->nullable();
            $table->timestamp('rts_at')->nullable();
            // The sweep and the banner both read status against the validity date.
            $table->index(['status', 'validity_until']);
        });

        $this->remap();

        Schema::table('cheques', function (Blueprint $table) {
            // The index the old axis was queried through has to go before the column can.
            $table->dropIndex(['disposition', 'validity_until']);
            $table->dropColumn('disposition');
        });
    }

    /**
     * Fold the old `status` + `disposition` pair into the new single flow.
     *
     * The status values are written as literals on purpose: this migration has already run,
     * and its behaviour must not move when the enums it once named are renamed.
     *
     * Public and idempotent so the test suite can exercise it directly. A terminal disposition
     * wins over the status it sat beside — a cancelled cheque is cancelled whatever its review
     * said — and everything still in play is placed by how far it actually got.
     *
     * @return array<string, int> how many rows landed on each new status
     */
    public function remap(): array
    {
        if (! Schema::hasColumn('cheques', 'disposition')) {
            return [];
        }

        $counts = [];
        $set = function (array $where, string $to) use (&$counts) {
            $q = DB::table('cheques');
            foreach ($where as $column => $value) {
                $value === null ? $q->whereNull($column) : $q->where($column, $value);
            }
            $n = $q->update(['status' => $to]);
            if ($n > 0) {
                $counts[$to] = ($counts[$to] ?? 0) + $n;
            }
        };

        // 1. A settled disposition decides on its own, whatever the review had said.
        $set(['disposition' => 'replaced'], 'replaced');
        $set(['disposition' => 'stale'], 'stale');
        $set(['disposition' => 'spoiled'], 'voided');
        $set(['disposition' => 'cancelled'], 'cancelled');
        $set(['disposition' => 'deposited'], 'deposited');
        $set(['disposition' => 'for_deposit'], 'forwarded_to_teller');
        $set(['disposition' => 'released'], 'released_to_payee');

        // 2. A disapproved cheque never left; it is a cancellation by another name.
        $set(['status' => 'disapproved'], 'cancelled');

        // 3. "Returned" meant the staff member had to fix it — which is now RTS, back to the start.
        $set(['status' => 'complies'], 'registered');

        // 4. Still in play: signed off and on an ACIC, signed off and waiting, or just claimed.
        DB::table('cheques')->where('status', 'approved')->whereNotNull('acic_id')
            ->update(['status' => 'acic_assigned']);
        $set(['status' => 'approved'], 'for_acic');
        $set(['status' => 'received'], 'for_acic');
        $set(['status' => 'used'], 'registered');

        return $counts;
    }

    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->string('disposition')->nullable();
            $table->dropIndex(['status', 'validity_until']);
            $table->dropColumn([
                'forward_to_name', 'forward_unit_name', 'received_by_name_in',
                'date_received_in', 'from_unit_name', 'exception_reason', 'rts_at',
            ]);
        });

        Schema::table('acics', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forwarded_to_teller_by');
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropConstrainedForeignId('returned_to_admin_by');
            $table->dropColumn([
                'forwarded_to_teller_at', 'forward_note', 'accepted_at', 'deposit_date',
                'deposit_bank', 'deposit_reference', 'deposit_note', 'returned_to_admin_at',
                'return_reason',
            ]);
        });

        Schema::dropIfExists('cheque_status_history');
    }
};
