<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A cheque's ACIC is now a link to an `acics` record, not free text on the cheque,
        // so there is no free-text value left for a correction request to propose.
        Schema::table('cheque_update_requests', function (Blueprint $table) {
            $table->dropColumn('proposed_accic_no');
        });
    }

    public function down(): void
    {
        Schema::table('cheque_update_requests', function (Blueprint $table) {
            $table->string('proposed_accic_no')->nullable()->after('proposed_cheque_date');
        });
    }
};
