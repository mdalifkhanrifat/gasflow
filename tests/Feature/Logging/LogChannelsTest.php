<?php

/**
 * Every log channel redacts secrets, and the json channel writes one JSON
 * object per line with the request id from Laravel's Context.
 */

use App\Logging\RedactSensitiveData;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;

beforeEach(function () {
    $this->logFile = storage_path('framework/testing/log-'.getmypid().'.log');
});

afterEach(function () {
    foreach (glob(storage_path('framework/testing/log-'.getmypid().'*')) ?: [] as $file) {
        unlink($file);
    }
});

/**
 * Send a channel's output to the test file and log a secret through it.
 */
function logSecretThrough(string $channel, string $file): string
{
    $setting = in_array($channel, ['stderr', 'json'], true) ? 'handler_with.stream' : 'path';
    config(["logging.channels.{$channel}.{$setting}" => $file]);

    Log::channel($channel)->warning('login failed', ['email' => 'owner@example.com', 'password' => 'hunter2']);

    return implode('', array_map('file_get_contents', glob(dirname($file).'/'.pathinfo($file, PATHINFO_FILENAME).'*') ?: []));
}

describe('log channels', function () {
    it('redacts secrets in every channel that writes to a file or stream', function (string $channel) {
        // Arrange: the channel writes to a test file

        // Act
        $written = logSecretThrough($channel, $this->logFile);

        // Assert
        expect($written)->toContain('owner@example.com')
            ->toContain('[redacted]')
            ->not->toContain('hunter2');
    })->with(['single', 'daily', 'monthly', 'stderr', 'json']);

    it('gives every channel that writes logs the redaction tap', function () {
        // Arrange
        $writers = collect(config('logging.channels'))
            ->reject(fn (array $channel, string $name) => $name === 'emergency'
                || ($channel['driver'] ?? null) === 'stack'
                || ($channel['handler'] ?? null) === NullHandler::class);

        // Act
        $withoutTap = $writers
            ->reject(fn (array $channel) => in_array(RedactSensitiveData::class, $channel['tap'] ?? [], true))
            ->keys()
            ->all();

        // Assert
        expect($withoutTap)->toBe([]);
    });

    it('writes json lines with the request id', function () {
        // Arrange
        Context::add('request_id', '01a1d873-bc62-7310-a955-165eb8a0887e');

        // Act
        $line = logSecretThrough('json', $this->logFile);

        // Assert
        $entry = json_decode(trim($line), true, flags: JSON_THROW_ON_ERROR);
        expect($entry['message'])->toBe('login failed')
            ->and($entry['level_name'])->toBe('WARNING')
            ->and($entry['context']['password'])->toBe('[redacted]')
            ->and($entry['extra']['request_id'])->toBe('01a1d873-bc62-7310-a955-165eb8a0887e');
    });
});
