<?php

/**
 * SensitiveDataProcessor: secrets in log context and extra become "[redacted]".
 */

use App\Logging\SensitiveDataProcessor;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * @param  array<array-key, mixed>  $context
 * @param  array<array-key, mixed>  $extra
 */
function redactedRecord(array $context, array $extra = []): LogRecord
{
    $record = new LogRecord(new DateTimeImmutable, 'test', Level::Warning, 'payment callback', $context, $extra);

    return (new SensitiveDataProcessor)($record);
}

describe('sensitive data processor', function () {
    it('masks passwords, tokens and secrets', function () {
        // Arrange
        $context = ['password' => 'hunter2', 'api_token' => 'tok', 'client_secret' => 'sec'];

        // Act
        $record = redactedRecord($context);

        // Assert
        expect($record->context)->toBe(['password' => '[redacted]', 'api_token' => '[redacted]', 'client_secret' => '[redacted]']);
    });

    it('masks keys in any spelling', function (string $key) {
        // Arrange
        $context = [$key => 'value'];

        // Act
        $record = redactedRecord($context);

        // Assert
        expect($record->context[$key])->toBe('[redacted]');
    })->with(['X-Api-Key', 'accessToken', 'PASSWORD_confirmation', 'Authorization', 'private_key', 'card-number']);

    it('masks short keys only when they are the whole key', function () {
        // Arrange
        $context = ['pin' => '1234', 'otp' => '998877', 'shipping' => 'courier', 'opinion' => 'fine'];

        // Act
        $record = redactedRecord($context);

        // Assert
        expect($record->context)->toBe(['pin' => '[redacted]', 'otp' => '[redacted]', 'shipping' => 'courier', 'opinion' => 'fine']);
    });

    it('masks values at any depth', function () {
        // Arrange
        $context = ['gateway' => ['request' => ['headers' => ['Authorization' => 'Bearer abc'], 'amount' => 500]]];

        // Act
        $record = redactedRecord($context);

        // Assert
        expect($record->context['gateway']['request'])->toBe(['headers' => ['Authorization' => '[redacted]'], 'amount' => 500]);
    });

    it('masks the extra data as well', function () {
        // Arrange
        $extra = ['session_token' => 'abc', 'request_id' => 'r-1'];

        // Act
        $record = redactedRecord([], $extra);

        // Assert
        expect($record->extra)->toBe(['session_token' => '[redacted]', 'request_id' => 'r-1']);
    });

    it('keeps everything else as it was', function () {
        // Arrange
        $context = ['invoice' => 'INV-1', 'amount' => 1250.5, 'paid' => true, 'lines' => [1, 2]];

        // Act
        $record = redactedRecord($context);

        // Assert
        expect($record->context)->toBe($context)
            ->and($record->message)->toBe('payment callback');
    });
});
