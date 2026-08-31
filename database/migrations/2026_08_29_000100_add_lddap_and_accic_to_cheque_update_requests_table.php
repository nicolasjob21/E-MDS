<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheque_update_requests', function (Blueprint $table) {
            // Staff may also propose corrections to the LDDAP / ACIC reference numbers.
            $table->string('proposed_lddap_no')->nullable()->after('proposed_cheque_date');
            $table->string('proposed_accic_no')->nullable()->after('proposed_lddap_no');
        });
    }

    public function down(): void
    {
        Schema::table('cheque_update_requests', function (Blueprint $table) {
            $table->dropColumn(['proposed_lddap_no', 'proposed_accic_no']);
        });
    }
};
