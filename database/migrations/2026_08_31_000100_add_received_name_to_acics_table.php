<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acics', function (Blueprint $table) {
            // Who accepted the ACIC when the recipient is not a system user — the name is
            // typed in at forward time. Exactly one of received_by / received_name is set.
            $table->string('received_name')->nullable()->after('received_by');
        });
    }

    public function down(): void
    {
        Schema::table('acics', function (Blueprint $table) {
            $table->dropColumn('received_name');
        });
    }
};
