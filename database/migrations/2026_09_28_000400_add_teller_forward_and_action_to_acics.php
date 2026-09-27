<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The teller's Forward (to Land Bank or to the payee) and Action (Completed or RTS) steps.
 *
 * On the ACIC: where it was forwarded, by whom and when; who took the action and when; and the
 * RTS reason. On each cheque and LDDAP: who received it and when (forwarded to the payee), and
 * the status the teller gave it on an RTS. Statuses themselves are text columns already.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acics', function (Blueprint $table) {
            $table->string('teller_forwarded_to', 20)->nullable();
            $table->foreignId('teller_forwarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('teller_forwarded_at')->nullable();
            $table->foreignId('teller_action_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('teller_action_at')->nullable();
            $table->text('rts_reason')->nullable();
        });

        foreach (['cheques', 'lddaps'] as $records) {
            Schema::table($records, function (Blueprint $table) {
                $table->string('payee_received_by')->nullable();
                $table->date('payee_received_on')->nullable();
                $table->string('rts_status', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['cheques', 'lddaps'] as $records) {
            Schema::table($records, function (Blueprint $table) {
                $table->dropColumn(['payee_received_by', 'payee_received_on', 'rts_status']);
            });
        }

        Schema::table('acics', function (Blueprint $table) {
            $table->dropConstrainedForeignId('teller_forwarded_by');
            $table->dropConstrainedForeignId('teller_action_by');
            $table->dropColumn(['teller_forwarded_to', 'teller_forwarded_at', 'teller_action_at', 'rts_reason']);
        });
    }
};
