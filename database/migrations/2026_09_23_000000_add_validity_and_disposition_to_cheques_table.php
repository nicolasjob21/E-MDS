<?php

use App\Support\Validity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cheque's second axis: where it physically is (`disposition`), and the 90-day validity
 * that runs against it.
 *
 * `status` is untouched — it still tracks issuance and the admin's review. A cheque takes a
 * disposition when its number is used, and the release / deposit paths open once the admin
 * has signed it off.
 *
 * Note the two pairs of similarly-named columns, which mean different things:
 *   · `received_by` / `received_at`     — the teller confirming physical receipt (existing)
 *   · `received_by_name` / `date_received` — who the cheque was released TO, and when (new)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            // Where the cheque is. Null while it sits unissued in the available pool.
            $table->string('disposition')->nullable()->after('status');

            // The 90-day clock: cheque_date + 90, recomputed whenever the date changes.
            $table->date('validity_until')->nullable()->after('disposition');
            $table->timestamp('stale_at')->nullable()->after('validity_until');
            $table->timestamp('expiry_alert_sent_at')->nullable()->after('stale_at');

            // A stale cheque and the one issued in its place.
            $table->foreignId('replaces_id')->nullable()->after('expiry_alert_sent_at')->constrained('cheques')->nullOnDelete();
            $table->foreignId('replaced_by_id')->nullable()->after('replaces_id')->constrained('cheques')->nullOnDelete();

            // Path A — released to the payee (or their authorised representative).
            $table->string('received_by_name')->nullable();
            $table->date('date_received')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->text('release_note')->nullable();

            // Path B, step 1 — forwarded to a teller for deposit.
            $table->foreignId('forwarded_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('forwarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('date_forwarded')->nullable();
            $table->text('forward_note')->nullable();

            // Path B, step 2 — the teller approved and handed it to the bank.
            $table->foreignId('approved_by_teller')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('teller_approved_at')->nullable();
            $table->string('bank_name')->nullable();
            $table->date('date_deposited')->nullable();
            $table->string('deposit_reference')->nullable();
            $table->text('deposit_note')->nullable();

            // The teller handing the request back.
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();

            // The daily sweep and the "expiring soon" banner both read this way round.
            $table->index(['disposition', 'validity_until']);
        });

        $this->backfill();
    }

    /**
     * Give every existing cheque a disposition and a validity date, and settle the ones that
     * are already past it.
     *
     * The status values here are written as literals on purpose: this migration has already
     * run, and its behaviour must not move when the enums it once named are reworked.
     *
     * Public so the test suite can exercise it directly. Idempotent: re-running it changes
     * nothing that is already correct, and it never sends a notification — the caller sends
     * one summary instead of a flood of back-dated alerts.
     *
     * @return array{dated: int, assigned: int, cancelled: int, stale: int}
     */
    public function backfill(): array
    {
        $now = Carbon::now();

        // 1. The 90-day date, for every cheque that carries a cheque date.
        $dated = 0;
        DB::table('cheques')->whereNotNull('cheque_date')->whereNull('validity_until')
            ->orderBy('id')->chunkById(500, function ($rows) use (&$dated) {
                foreach ($rows as $row) {
                    DB::table('cheques')->where('id', $row->id)->update([
                        'validity_until' => Validity::until($row->cheque_date)?->toDateString(),
                    ]);
                    $dated++;
                }
            });

        // 2. An issued cheque is Assigned; a disapproved one was cancelled before release.
        //    An available cheque keeps a null disposition — it is still in the pool.
        $cancelled = DB::table('cheques')->whereNull('disposition')
            ->where('status', 'disapproved')
            ->update(['disposition' => 'cancelled']);

        $assigned = DB::table('cheques')->whereNull('disposition')
            ->where('status', '!=', 'available')
            ->update(['disposition' => 'assigned']);

        // 3. Anything already past its validity is stale from the start — silently.
        $stale = DB::table('cheques')
            ->whereIn('disposition', ['assigned', 'released', 'for_deposit'])
            ->whereNotNull('validity_until')
            ->whereDate('validity_until', '<', Validity::today()->toDateString())
            ->update([
                'disposition' => 'stale',
                'stale_at' => $now,
            ]);

        return compact('dated', 'assigned', 'cancelled', 'stale');
    }

    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropIndex(['disposition', 'validity_until']);
            $table->dropConstrainedForeignId('replaces_id');
            $table->dropConstrainedForeignId('replaced_by_id');
            $table->dropConstrainedForeignId('released_by');
            $table->dropConstrainedForeignId('forwarded_to');
            $table->dropConstrainedForeignId('forwarded_by');
            $table->dropConstrainedForeignId('approved_by_teller');
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn([
                'disposition', 'validity_until', 'stale_at', 'expiry_alert_sent_at',
                'received_by_name', 'date_received', 'released_at', 'release_note',
                'date_forwarded', 'forward_note',
                'teller_approved_at', 'bank_name', 'date_deposited', 'deposit_reference', 'deposit_note',
                'returned_at', 'return_reason',
            ]);
        });
    }
};
