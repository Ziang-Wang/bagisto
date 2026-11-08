<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Analytics 4
    |--------------------------------------------------------------------------
    |
    | Web stream for https://www.urmotorparts.com. The measurement ID is not a
    | secret (it is printed in every page), so it can live here. Only the live
    | store reports hits by default, which keeps local and staging traffic out
    | of the store's reports; set GA4_ENABLED=true to test elsewhere. The live
    | host is the signal because the production image's .env says
    | APP_ENV=local.
    |
    */

    'ga4' => [
        'measurement_id' => env('GA4_MEASUREMENT_ID', 'G-SSS6DXLMD2'),

        'enabled' => (bool) env('GA4_ENABLED', parse_url((string) env('APP_URL'), PHP_URL_HOST) === 'www.urmotorparts.com'),
    ],

];
