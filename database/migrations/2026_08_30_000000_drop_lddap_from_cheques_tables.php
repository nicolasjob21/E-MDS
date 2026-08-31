<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropColumn('lddap_no');
        });

        Schema::table('cheque_update_requests', function (Blueprint $table) {
            $table->dropColumn('proposed_lddap_no');
        });
    }

    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->string('lddap_no')->nullable()->after('cheque_date');
        });

        Schema::table('cheque_update_requests', function (Blueprint $table) {
            $table->string('proposed_lddap_no')->nullable()->after('proposed_cheque_date');
        });
    }
};
