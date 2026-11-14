<?php

namespace App\Services\Licensing\Issuing;

use JsonException;
use RuntimeException;
use SensitiveParameter;
use SodiumException;

/**
 * The private signing key at rest, sealed with the owner's passphrase:
 * Argon2id derives the key, XChaCha20-Poly1305 encrypts the secret key, and the
 * key id and public key are authenticated with it, so neither can be swapped.
 * Without the passphrase the file is useless.
 */
final class SigningKeyFile
{
    public const int MIN_PASSPHRASE_LENGTH = 12;

    public static function seal(
        SigningKey $key,
        #[SensitiveParameter] string $passphrase,
        int $opsLimit = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
        int $memLimit = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
    ): string {
        if (mb_strlen($passphrase) < self::MIN_PASSPHRASE_LENGTH) {
            throw new RuntimeException('The passphrase needs at least '.self::MIN_PASSPHRASE_LENGTH.' characters.');
        }

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $wrappingKey = self::wrappingKey($passphrase, $salt, $opsLimit, $memLimit);

        $sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $key->secretKey,
            self::associatedData($key->keyId, $key->publicKey),
            $nonce,
            $wrappingKey,
        );
        sodium_memzero($wrappingKey);

        return json_encode([
            'format' => 'gasflow-license-signing-key/1',
            'key_id' => $key->keyId,
            'algorithm' => 'ed25519',
            'public_key' => $key->publicKeyBase64(),
            'kdf' => ['name' => 'argon2id13', 'opslimit' => $opsLimit, 'memlimit' => $memLimit, 'salt' => base64_encode($salt)],
            'cipher' => ['name' => 'xchacha20poly1305-ietf', 'nonce' => base64_encode($nonce), 'secret_key' => base64_encode($sealed)],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @throws RuntimeException when the passphrase is wrong or the file was changed
     */
    public static function open(string $contents, #[SensitiveParameter] string $passphrase): SigningKey
    {
        try {
            $file = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
            $keyId = data_get($file, 'key_id');
            $opsLimit = data_get($file, 'kdf.opslimit');
            $memLimit = data_get($file, 'kdf.memlimit');

            if (! is_string($keyId) || ! is_int($opsLimit) || ! is_int($memLimit)) {
                throw new RuntimeException('Incomplete key file.');
            }

            $publicKey = self::base64(data_get($file, 'public_key'));
            $wrappingKey = self::wrappingKey($passphrase, self::base64(data_get($file, 'kdf.salt')), $opsLimit, $memLimit);

            $secretKey = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                self::base64(data_get($file, 'cipher.secret_key')),
                self::associatedData($keyId, $publicKey),
                self::base64(data_get($file, 'cipher.nonce')),
                $wrappingKey,
            );
            sodium_memzero($wrappingKey);
        } catch (JsonException|SodiumException|RuntimeException) {
            throw new RuntimeException('This is not a readable signing key file.');
        }

        if ($secretKey === false) {
            throw new RuntimeException('Wrong passphrase, or the key file was changed.');
        }

        return new SigningKey($keyId, $publicKey, $secretKey);
    }

    private static function wrappingKey(#[SensitiveParameter] string $passphrase, string $salt, int $opsLimit, int $memLimit): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $passphrase,
            $salt,
            $opsLimit,
            $memLimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    private static function associatedData(string $keyId, string $publicKey): string
    {
        return "gasflow-license-signing-key/1\n{$keyId}\n".base64_encode($publicKey);
    }

    private static function base64(mixed $value): string
    {
        $decoded = is_string($value) ? base64_decode($value, true) : false;

        if ($decoded === false) {
            throw new RuntimeException('Not base64.');
        }

        return $decoded;
    }
}
