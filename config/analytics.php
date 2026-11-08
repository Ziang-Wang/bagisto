<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Analytics 4
    |--------------------------------------------------------------------------
    |
    | Web stream for https://www.urmotorparts.com. The measurement ID is not a
    | secret (it is printed in every page), so it can live here. Only
    | production reports hits by default, which keeps local and staging traffic
    | out of the store's reports; set GA4_ENABLED=true to test elsewhere.
    |
    */

    'ga4' => [
        'measurement_id' => env('GA4_MEASUREMENT_ID', 'G-SSS6DXLMD2'),

        'enabled' => (bool) env('GA4_ENABLED', env('APP_ENV') === 'production'),
    ],

];
