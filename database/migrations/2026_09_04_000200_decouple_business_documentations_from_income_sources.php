<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('residence_business_documentations', 'business_name')) {
            Schema::table('residence_business_documentations', function (Blueprint $table): void {
                $table->string('business_name')->nullable()->after('category');
            });
        }

        if (Schema::hasColumn('residence_business_documentations', 'income_source_id')) {
            DB::table('residence_business_documentations')
                ->where('category', 'business')
                ->whereNotNull('income_source_id')
                ->orderBy('id')
                ->chunkById(100, function ($documentations): void {
                    $sources = DB::table('income_sources')
                        ->whereIn('id', $documentations->pluck('income_source_id')->filter()->all())
                        ->get(['id', 'business_name', 'source_name'])
                        ->keyBy('id');

                    foreach ($documentations as $documentation) {
                        $source = $sources->get($documentation->income_source_id);
                        $name = trim((string) ($source?->business_name ?: $source?->source_name));
                        DB::table('residence_business_documentations')
                            ->where('id', $documentation->id)
                            ->update(['business_name' => $name === '' ? null : $name]);
                    }
                });
        }

        $legacyColumn = Schema::hasColumn('residence_business_documentations', 'legacy_income_source_id')
            ? 'legacy_income_source_id'
            : 'income_source_id';
        $foreignKey = collect(Schema::getForeignKeys('residence_business_documentations'))
            ->first(fn (array $key): bool => in_array($legacyColumn, $key['columns'], true));
        if ($foreignKey !== null) {
            Schema::table('residence_business_documentations', function (Blueprint $table) use ($foreignKey): void {
                $table->dropForeign(
                    DB::getDriverName() === 'sqlite' ? $foreignKey['columns'] : $foreignKey['name'],
                );
            });
        }

        $obsoleteIndex = collect(Schema::getIndexes('residence_business_documentations'))
            ->firstWhere('name', 'rbd_income_source_category_unique');
        if ($obsoleteIndex !== null) {
            Schema::table('residence_business_documentations', function (Blueprint $table): void {
                $table->dropUnique('rbd_income_source_category_unique');
            });
        }

        if (Schema::hasColumn('residence_business_documentations', 'income_source_id')
            && ! Schema::hasColumn('residence_business_documentations', 'legacy_income_source_id')) {
            Schema::table('residence_business_documentations', function (Blueprint $table): void {
                // Retain the former value only as inert historical provenance. Runtime code never
                // reads it and no foreign key connects it to Report/Check-owned IncomeSource rows.
                $table->renameColumn('income_source_id', 'legacy_income_source_id');
            });
        }

        DB::table('media_references')
            ->whereNotNull('residence_business_documentation_id')
            ->update(['income_source_id' => null]);
    }

    public function down(): void
    {
        Schema::table('residence_business_documentations', function (Blueprint $table): void {
            $table->renameColumn('legacy_income_source_id', 'income_source_id');
        });
        Schema::table('residence_business_documentations', function (Blueprint $table): void {
            $table->foreign('income_source_id')->references('id')->on('income_sources')->nullOnDelete();
            $table->unique(['income_source_id', 'category'], 'rbd_income_source_category_unique');
            $table->dropColumn('business_name');
        });
    }
};
