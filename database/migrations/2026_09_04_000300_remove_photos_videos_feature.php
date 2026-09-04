<?php

use App\Services\Maintenance\PhotosVideosRemoval;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        app(PhotosVideosRemoval::class)->execute();

        Schema::dropIfExists('documentation_telegram_deliveries');

        if (Schema::hasTable('media_references') && Schema::hasColumn('media_references', 'residence_business_documentation_id')) {
            Schema::table('media_references', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('residence_business_documentation_id');
            });
        }

        Schema::dropIfExists('residence_business_documentations');
    }

    public function down(): void
    {
        // Intentionally irreversible: the retired feature and its records must not be recreated.
    }
};
