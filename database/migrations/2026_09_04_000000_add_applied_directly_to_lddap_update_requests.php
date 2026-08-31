<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An admin may correct an LDDAP directly, taking effect immediately. It is still recorded
     * here — with a mandatory reason — so the record has one correction history whether the
     * change came from a staff request or straight from an admin.
     */
    public function up(): void
    {
        Schema::table('lddap_update_requests', function (Blueprint $table) {
            $table->boolean('applied_directly')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('lddap_update_requests', function (Blueprint $table) {
            $table->dropColumn('applied_directly');
        });
    }
};
