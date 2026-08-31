<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            // Reference numbers of the disbursement documents the cheque is issued against.
            // Nullable so cheques used before this change keep their existing rows.
            $table->string('lddap_no')->nullable()->after('cheque_date');
            $table->string('accic_no')->nullable()->after('lddap_no');
        });
    }

    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropColumn(['lddap_no', 'accic_no']);
        });
    }
};
