<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Purely additive — every existing row (CI Activity Supporting Proof, prior ad-hoc
        // uploads) gets NULL here automatically and is completely unaffected. Only new uploads
        // made through the redesigned Residence/Business Documentation workspace ever set it.
        Schema::table('media_references', function (Blueprint $table) {
            $table->foreignId('residence_business_documentation_id')->nullable()->after('income_source_id')
                ->constrained('residence_business_documentations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media_references', function (Blueprint $table) {
            $table->dropConstrainedForeignId('residence_business_documentation_id');
        });
    }
};
