<?php

namespace App\Services\Licensing\Issuing;

use App\Services\Licensing\CanonicalJson;

/**
 * Produces a license document: {"payload": {...}, "signature": "<base64>"}.
 * The payload's key_id is always the signing key's id.
 */
final class LicenseSigner
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function sign(array $payload, SigningKey $key): string
    {
        $payload['key_id'] = $key->keyId;
        $signature = $key->sign(CanonicalJson::encode($payload));

        return json_encode(
            ['payload' => $payload, 'signature' => base64_encode($signature)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }
}
