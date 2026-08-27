<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudinary connection
    |--------------------------------------------------------------------------
    |
    | Either set CLOUDINARY_URL (the single "cloudinary://key:secret@cloud_name"
    | connection string Cloudinary's own dashboard/CLI give you), or the three
    | separate CLOUDINARY_CLOUD_NAME / CLOUDINARY_API_KEY / CLOUDINARY_API_SECRET
    | variables below. When neither is set, Cloudinary storage is disabled and
    | new Residence/Business uploads transparently keep using this app's
    | existing local/private storage instead — see CloudinaryMediaStorage::enabled().
    |
    */
    'url' => env('CLOUDINARY_URL'),
    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
    'api_key' => env('CLOUDINARY_API_KEY'),
    'api_secret' => env('CLOUDINARY_API_SECRET'),
    'secure' => true,

    // Root folder every BRBI-CIMS asset is organized under in the Cloudinary account —
    // see CloudinaryMediaStorage's own folder-building logic for the full structure.
    'root_folder' => env('CLOUDINARY_ROOT_FOLDER', 'BRBI-CIMS'),

    /*
    |--------------------------------------------------------------------------
    | Upload/delivery presets
    |--------------------------------------------------------------------------
    |
    | Applied as an *incoming* transformation at upload time (so the stored
    | master copy itself is already the normalized size/quality — Cloudinary
    | never keeps an untouched multi-megabyte original around), and reused
    | as the main-image delivery transformation everywhere the asset is shown
    | or embedded (Web/PDF/DOCX). max_dimension bounds width AND height
    | proportionally (Cloudinary's "limit" crop mode) and never upscales a
    | smaller image.
    |
    */
    'photo' => [
        'max_dimension' => 1920,
        'quality' => 'auto:good',
    ],
    'map_screenshot' => [
        // Map screenshots need their text/labels to stay legible, so this keeps a
        // higher quality floor than ordinary photos rather than compressing as hard.
        'max_dimension' => 1920,
        'quality' => 'auto:best',
    ],

    // A single on-the-fly delivery transformation (never a separately stored/uploaded
    // asset) reused for table/gallery/form thumbnails, to stay within Cloudinary's
    // free-tier transformation usage.
    'thumbnail' => [
        'width' => 500,
        'quality' => 'auto',
    ],
];
