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
    | never trusted there. Private keys are never stored in the repository.
    |
    */

    'public_keys' => json_decode(
        (string) file_get_contents(resource_path('licensing/public-keys.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    ),

];
