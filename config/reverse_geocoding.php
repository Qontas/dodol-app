<?php

return [
    // Only the explicit CLI backfill calls a provider; web requests never do.
    'provider' => env('REVERSE_GEOCODING_PROVIDER') ?: (env('GOOGLE_MAPS_API_KEY') ? 'google' : 'nominatim'),

    // Supply a public service contact you consent to send to OpenStreetMap.
    // Never derive this from owner records, MAIL_FROM_ADDRESS or billing details.
    'user_agent' => env('REVERSE_GEOCODING_USER_AGENT', 'KioskAddressBackfill/1.0'),
    'contact_email' => env('REVERSE_GEOCODING_CONTACT_EMAIL'),
    'google_api_key' => env('GOOGLE_MAPS_API_KEY'),

    // Hard ceilings in ReverseGeocoder: 2s connect, 5s total, no retries/redirects.
    'connect_timeout' => 2,
    'timeout' => 5,

    // One shared file, irrespective of provider/owner, across CLI processes on
    // this host. Multiple hosts must share this path or run only one backfill.
    'lock_path' => storage_path('framework/cache/reverse-geocoding.lock'),
];
