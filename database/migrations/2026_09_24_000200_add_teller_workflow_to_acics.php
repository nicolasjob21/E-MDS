<?php

use App\Enums\AcicType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The teller's half of an ACIC's life: forwarded, claimed, lodged with Land Bank, credited —
 * with the bank's returns in between.
 *
 * It is a **second axis** on the ACIC, beside `status` (open → used → approved → …): the same
 * ACIC can be Approved on its own axis and Pending on the teller's. Null means it has never
 * been sent to a teller, and returning it to the admin clears it back to null so the next
 * forward starts a fresh cycle.
 *
 * Both kinds of record travel with it, so the flag columns land on `cheques` and `lddaps` alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acics', function (Blueprint $table) {
            // What the ACIC carries. Fixed by the first record on it.
            $table->string('type')->nullable()->after('acic_number');
            // The teller axis.
            $table->string('teller_status')->nullable()->after('status');
            $table->timestamp('forwarded_to_land_bank_at')->nullable();
            $table->string('transmittal_no')->nullable();
            $table->text('land_bank_note')->nullable();
            $table->timestamp('returned_by_bank_at')->nullable();
            $table->text('bank_return_reason')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->string('bank_confirmation_no')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('completion_note')->nullable();

            $table->index(['teller_status', 'acic_number']);
        });

        Schema::create('acic_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acic_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            /** The named step — forwarded_to_teller, accepted, forwarded_to_land_bank, … */
            $table->string('action');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            /** The step's own fields, as given. */
            $table->json('details')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['acic_id', 'id']);
        });

        // The bank's return lands on the records it actually affected, not on all of them.
        foreach (['cheques', 'lddaps'] as $records) {
            Schema::table($records, function (Blueprint $table) {
                $table->boolean('returned_by_bank')->default(false);
                $table->text('bank_return_note')->nullable();
                $table->index('returned_by_bank');
            });
        }

        $this->backfill();
    }

    /**
     * Give existing ACICs a type, and carry the old one-step deposit onto the new flow.
     *
     * The status values are literals on purpose: this migration's behaviour must not move when
     * the enums it once named are reworked. Public and idempotent, so the suite can call it.
     *
     * @return array{typed: int, completed: int}
     */
    public function backfill(): array
    {
        // An ACIC's type is whatever it already carries.
        DB::table('acics')->whereNull('type')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('lddaps')->whereColumn('lddaps.acic_id', 'acics.id'))
            ->update(['type' => AcicType::Lddap->value]);

        $typed = DB::table('acics')->whereNull('type')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('cheques')->whereColumn('cheques.acic_id', 'acics.id'))
            ->update(['type' => AcicType::Cheque->value]);

        // A cheque that was "deposited" under the old one-step flow is credited and done.
        $completed = DB::table('cheques')->where('status', 'deposited')->update(['status' => 'completed']);

        // Its ACIC has been through the bank, so the teller axis says so too.
        DB::table('acics')->whereNull('teller_status')->whereNotNull('deposit_date')
            ->update(['teller_status' => 'completed']);

        return compact('typed', 'completed');
    }

    public function down(): void
    {
        foreach (['cheques', 'lddaps'] as $records) {
            Schema::table($records, function (Blueprint $table) {
                $table->dropIndex(['returned_by_bank']);
                $table->dropColumn(['returned_by_bank', 'bank_return_note']);
            });
        }

        Schema::dropIfExists('acic_history');

        Schema::table('acics', function (Blueprint $table) {
            $table->dropIndex(['teller_status', 'acic_number']);
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn([
                'type', 'teller_status', 'forwarded_to_land_bank_at', 'transmittal_no',
                'land_bank_note', 'returned_by_bank_at', 'bank_return_reason', 'credited_at',
                'bank_confirmation_no', 'completion_note',
            ]);
        });
    }
};
