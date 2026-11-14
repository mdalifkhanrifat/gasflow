<?php

namespace App\Modules\Licensing;

use App\Modules\Licensing\Enums\LicenseStatus;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use JsonException;
use SodiumException;

/**
 * Verifies a license document (design 09 §3, §8):
 *
 *     {"payload": {... "key_id": "k2026a" ...}, "signature": "<base64 Ed25519>"}
 *
 * The signature covers CanonicalJson::encode($payload). The key is chosen by the
 * signed key_id and must be one of the trusted public keys, which come only from
 * the build (resources/licensing/public-keys.json), never from the environment.
 *
 * Dates are whole calendar days: valid from the first second of valid_from
 * to the last second of valid_until, then offline_grace_days of grace.
 */
final readonly class LicenseVerifier
{
    /**
     * @param  array<string, string>  $publicKeys  key_id => base64 Ed25519 public key
     */
    public function __construct(private array $publicKeys) {}

    public function verify(string $document, CarbonInterface $now): LicenseCheck
    {
        try {
            $decoded = json_decode($document, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return LicenseCheck::invalid('The license is not valid JSON.');
        }

        $payload = is_array($decoded) ? ($decoded['payload'] ?? null) : null;
        $signature = is_array($decoded) ? ($decoded['signature'] ?? null) : null;

        if (! is_array($payload) || array_is_list($payload) || ! is_string($signature)) {
            return LicenseCheck::invalid('The license needs a payload object and a signature.');
        }

        $keyId = $payload['key_id'] ?? null;
        $publicKey = is_string($keyId) ? $this->publicKey($keyId) : null;

        if ($publicKey === null) {
            return LicenseCheck::invalid('The license is signed with an unknown key.');
        }

        if (! $this->signatureMatches($payload, $signature, $publicKey)) {
            return LicenseCheck::invalid('The license signature does not match.');
        }

        try {
            $license = License::fromPayload($payload);
        } catch (InvalidArgumentException $exception) {
            return LicenseCheck::invalid("The license field [{$exception->getMessage()}] is malformed.");
        }

        return $this->evaluateDates($license, $now);
    }

    private function evaluateDates(License $license, CarbonInterface $now): LicenseCheck
    {
        if ($now->lessThan($license->validFrom)) {
            return new LicenseCheck(LicenseStatus::Invalid, "The license is valid from {$license->validFrom->toDateString()}.", $license);
        }

        $endsAt = $license->validUntil->endOfDay();

        if ($now->lessThanOrEqualTo($endsAt)) {
            return new LicenseCheck(LicenseStatus::Active, "The license is valid until {$license->validUntil->toDateString()}.", $license);
        }

        $graceEndsAt = $endsAt->addDays($license->offlineGraceDays);

        if ($now->lessThanOrEqualTo($graceEndsAt)) {
            return new LicenseCheck(LicenseStatus::Grace, "The license expired on {$license->validUntil->toDateString()}; grace ends {$graceEndsAt->toDateString()}.", $license);
        }

        return new LicenseCheck(LicenseStatus::Expired, "The license expired on {$license->validUntil->toDateString()}.", $license);
    }

    /**
     * @return non-empty-string|null
     */
    private function publicKey(string $keyId): ?string
    {
        $encoded = $this->publicKeys[$keyId] ?? null;
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;

        return is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $key : null;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  non-empty-string  $publicKey
     */
    private function signatureMatches(array $payload, string $signature, string $publicKey): bool
    {
        $raw = base64_decode($signature, true);

        if (! is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($raw, CanonicalJson::encode($payload), $publicKey);
        } catch (SodiumException|JsonException) {
            return false;
        }
    }
}
