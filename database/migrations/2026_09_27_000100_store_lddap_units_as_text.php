<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every Unit field is now a choice from the PCG unit list (config/pcg-units.json), saved as the
 * unit's name in a text column — so the LDDAP's unit references to the `units` table become
 * text columns, and the table goes.
 *
 * Each record keeps the unit name it has today, copied across as-is: this migration changes
 * where the name is stored, not what it says. Mapping old names onto the list is a separate,
 * approved step.
 */
return new class extends Migration
{
    /** table => [old id column => new name column] */
    private const COLUMNS = [
        'lddaps' => [
            'unit_id' => 'unit_name',
            'forward_unit_id' => 'forward_unit_name',
            'return_unit_id' => 'return_unit_name',
        ],
        'lddap_routing_history' => [
            'unit_id' => 'unit_name',
        ],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $id => $name) {
                    $t->string($name)->nullable()->after($id);
                }
            });

            foreach ($columns as $id => $name) {
                DB::table($table)->whereNotNull($id)->update([
                    $name => DB::raw("(select units.name from units where units.id = {$table}.{$id})"),
                ]);
            }

            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach (array_keys($columns) as $id) {
                    $t->dropConstrainedForeignId($id);
                }
            });
        }

        Schema::dropIfExists('units');
    }

    public function down(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Every name in use becomes a unit again.
        $names = collect(self::COLUMNS)
            ->flatMap(fn ($columns, $table) => collect($columns)
                ->flatMap(fn ($name) => DB::table($table)->whereNotNull($name)->distinct()->pluck($name)))
            ->unique()->sort()->values();
        DB::table('units')->insert($names->map(fn ($n) => [
            'name' => $n, 'created_at' => now(), 'updated_at' => now(),
        ])->all());

        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $id => $name) {
                    $t->foreignId($id)->nullable()->after($name)->constrained('units')->nullOnDelete();
                }
            });

            foreach ($columns as $id => $name) {
                DB::table($table)->whereNotNull($name)->update([
                    $id => DB::raw("(select units.id from units where units.name = {$table}.{$name})"),
                ]);
            }

            Schema::table($table, function (Blueprint $t) use ($columns) {
                $t->dropColumn(array_values($columns));
            });
        }
    }
};
