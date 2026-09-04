<?php

use App\Models\BusinessCheck;
use App\Models\CiActivity;
use App\Models\CibiReport;
use App\Models\IncomeSource;
use App\Models\ResidenceCheck;

return [
    'documents_root' => env('CIMS_DOCUMENTS_ROOT'),
    'report_disk' => env('REPORT_FILESYSTEM_DISK', 'local'),
    'media_disk' => env('MEDIA_FILESYSTEM_DISK', 'local'),
    'media' => [
        'max_files_per_upload' => 10,
        'image_max_kilobytes' => 10 * 1024,
        'video_max_kilobytes' => 50 * 1024,
        'php_upload_max_filesize' => ini_get('upload_max_filesize') ?: 'unknown',
        'php_post_max_size' => ini_get('post_max_size') ?: 'unknown',
    ],
    'cloudinary' => [
        'url' => env('CLOUDINARY_URL'),
        'root' => 'brbi-cims',
    ],
    'demo_data_enabled' => (bool) env('CIMS_SEED_DEMO_DATA', false),

    // Explicit, intentional opt-in for factory database writes outside the testing environment
    // (e.g. a deliberate disposable local database). Must default to false — see
    // Database\Factories\Factory for the guard this backs.
    'allow_factory_writes' => (bool) env('ALLOW_FACTORY_WRITES', false),

    'display_timezone' => env('CIMS_DISPLAY_TIMEZONE', 'Asia/Manila'),

    'reports' => [
        'default_paper' => [
            'width_inches' => 8.5,
            'height_inches' => 13.0,
        ],
    ],

    'progress' => [
        'strategy' => 'required_items',
        'weighted' => false,
    ],

    'client_folder_ownership' => [
        'primary_investigators' => 1,
        'many_to_many_enabled' => false,
    ],

    'editing_presence_types' => [
        'cibi_report' => CibiReport::class,
        'income_source' => IncomeSource::class,
        'residence_check' => ResidenceCheck::class,
        'business_check' => BusinessCheck::class,
        'ci_activity' => CiActivity::class,
    ],
];
