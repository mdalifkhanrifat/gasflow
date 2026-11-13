<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IP geolocation (local database, no third-party calls)
    |--------------------------------------------------------------------------
    |
    | The request context turns the client IP into a city and country with the
    | DB-IP City Lite database (CC BY 4.0, "IP Geolocation by DB-IP",
    | https://db-ip.com). "php artisan audit:geoip-update" downloads it monthly.
    | %s in the download URL is replaced by the month (YYYY-MM).
    |
    */

    'geoip' => [
        'database' => env('GEOIP_DATABASE', storage_path('app/geoip/dbip-city-lite.mmdb')),
        'download_url' => env('GEOIP_DOWNLOAD_URL', 'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz'),
    ],

];
