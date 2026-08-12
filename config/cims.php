<?php

return [
    'report_disk' => env('REPORT_FILESYSTEM_DISK', 'local'),
    'media_disk' => env('MEDIA_FILESYSTEM_DISK', 'local'),
    'media' => [
        'max_files_per_upload' => 10,
        'image_max_kilobytes' => 10 * 1024,
        'video_max_kilobytes' => 50 * 1024,
    ],
    'demo_data_enabled' => (bool) env('CIMS_SEED_DEMO_DATA', false),

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
];
