<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replace the old free-text encashment fields with a proper "received" lifecycle step:
        // a teller account confirms receipt, moving the cheque used -> received.
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropColumn(['teller_name', 'cashed_at']);
        });

        Schema::table('cheques', function (Blueprint $table) {
            $table->foreignId('received_by')->nullable()->after('used_at')->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable()->after('received_by');
        });
    }

    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn('received_at');
        });

        Schema::table('cheques', function (Blueprint $table) {
            $table->string('teller_name')->nullable()->after('used_at');
            $table->date('cashed_at')->nullable()->after('teller_name');
        });
    }
};
