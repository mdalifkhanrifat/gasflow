<?php

namespace App\Modules\Licensing\Issuing;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * An Ed25519 license signing key pair. The secret half exists only in memory
 * while signing; at rest it is always sealed in a SigningKeyFile.
 */
final readonly class SigningKey
{
    public const string ID_PATTERN = '~\A[a-z0-9][a-z0-9-]{1,38}\z~';

    public function __construct(
        public string $keyId,
        public string $publicKey,
        #[SensitiveParameter] public string $secretKey,
    ) {
        if (preg_match(self::ID_PATTERN, $keyId) !== 1) {
            throw new InvalidArgumentException('A key id has 2–39 lowercase letters, digits or dashes.');
        }

        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || ! hash_equals($publicKey, sodium_crypto_sign_publickey_from_secretkey($secretKey))) {
            throw new InvalidArgumentException('The public and secret key do not belong together.');
        }
    }

    public static function generate(string $keyId): self
    {
        $pair = sodium_crypto_sign_keypair();

        return new self($keyId, sodium_crypto_sign_publickey($pair), sodium_crypto_sign_secretkey($pair));
    }

    public function publicKeyBase64(): string
    {
        return base64_encode($this->publicKey);
    }

    public function sign(string $message): string
    {
        /** @var non-empty-string $secretKey checked in the constructor */
        $secretKey = $this->secretKey;

        return sodium_crypto_sign_detached($message, $secretKey);
    }
}
