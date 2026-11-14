<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted license signing keys (design 09 §3)
    |--------------------------------------------------------------------------
    |
    | key_id => base64 Ed25519 public key, read from a file that is part of the
    | build. It is deliberately not configurable through the environment: whoever
    | could choose the trusted keys could sign their own license. Release images
    | replace this file with the production keys only, so the development key is
    | never trusted there. Private keys are never stored in the repository; the
    | owner keeps them, sealed with a passphrase (php artisan license:keygen).
    |
    */

    'public_keys_file' => resource_path('licensing/public-keys.json'),

    'public_keys' => json_decode(
        (string) file_get_contents(resource_path('licensing/public-keys.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    ),

    /*
    | Where this installation's license lives (git-ignored). The owner issues it
    | with php artisan license:issue; nobody can run the app without one.
    */

    'license_file' => storage_path('app/license/license.json'),

    /*
    | Route names that may still write while the license is locked (design 09
    | §3: read-only mode). Everything else that is not GET/HEAD/OPTIONS gets 423.
    | Login, logout (Phase 4) and installing a new license (Phase 3) go here.
    */

    'locked_write_routes' => [],

];
