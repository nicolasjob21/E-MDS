<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Staff now propose the corrected values themselves; the admin approves them.
        Schema::table('cheque_update_requests', function (Blueprint $table) {
            $table->string('proposed_payee_name')->nullable()->after('cheque_id');
            $table->decimal('proposed_amount', 15, 2)->nullable()->after('proposed_payee_name');
            $table->date('proposed_cheque_date')->nullable()->after('proposed_amount');
        });
    }

    public function down(): void
    {
        Schema::table('cheque_update_requests', function (Blueprint $table) {
            $table->dropColumn(['proposed_payee_name', 'proposed_amount', 'proposed_cheque_date']);
        });
    }
};
