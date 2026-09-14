<?php

use App\Models\ActivityDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_definitions', function (Blueprint $table): void {
            $table->string('normalized_name')->nullable()->after('name');
        });

        DB::table('activity_definitions')->orderBy('id')->each(function (object $definition): void {
            DB::table('activity_definitions')->where('id', $definition->id)->update([
                'normalized_name' => ActivityDefinition::normalizedNameKey($definition->name),
            ]);
        });

        Schema::table('activity_definitions', function (Blueprint $table): void {
            $table->unique('normalized_name', 'activity_definitions_normalized_name_unique');
        });
        Schema::table('activity_definitions', function (Blueprint $table): void {
            $table->string('normalized_name')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('activity_definitions', function (Blueprint $table): void {
            $table->dropUnique('activity_definitions_normalized_name_unique');
            $table->dropColumn('normalized_name');
        });
    }
};
