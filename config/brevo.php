<?php

return [
    'api_key' => env('BREVO_API_KEY'),
    'sender_email' => env('BREVO_SENDER_EMAIL'),
    'sender_name' => env('BREVO_SENDER_NAME', env('APP_NAME', 'BRBI CIMS')),
    'timeout_seconds' => (int) env('BREVO_TIMEOUT_SECONDS', 10),
];
