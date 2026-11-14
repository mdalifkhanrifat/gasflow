<?php

/**
 * StatusRegistry: cached read model of the status catalogue (labels in the
 * active locale, tones, allowed transitions) and the UI's status source.
 */

use App\Services\Lifecycle\StatusRegistry;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Enums\LifecycleTestPeriodStatus;

function registry(): StatusRegistry
{
    return app(StatusRegistry::class);
}

/**
 * A new registry instance, as the next request or queued job would get it.
 */
function freshRegistry(): StatusRegistry
{
    app()->forgetScopedInstances();

    return app(StatusRegistry::class);
}

function addPeriodStatus(string $code, string $label): void
{
    DB::table('status_definitions')->insert([
        'entity_name' => 'lifecycle_test_periods', 'code' => $code, 'label' => $label,
        'tone' => 'warning', 'is_system' => false,
    ]);
}

describe('labels and tones', function () {
    it('returns the english label and the tone of a status', function () {
        // Act
        $label = registry()->label('lifecycle_test_periods', 'collecting', 'en');
        $tone = registry()->tone('lifecycle_test_periods', 'collecting');

        // Assert
        expect($label)->toBe('Collecting')
            ->and($tone)->toBe('info');
    });

    it('returns the bangla label in the bangla locale', function () {
        // Arrange
        app()->setLocale('bn');

        // Act
        $label = registry()->label('lifecycle_test_items', 'active');

        // Assert
        expect($label)->toBe('সক্রিয়');
    });

    it('falls back to english when a status has no translation', function () {
        // Act
        $label = registry()->label('lifecycle_test_periods', 'open', 'bn');

        // Assert
        expect($label)->toBe('Open');
    });

    it('uses the generic status set for tables without their own statuses', function () {
        // Act
        $codes = array_keys(registry()->statusesFor('lifecycle_test_items'));

        // Assert
        expect($codes)->toBe(['active', 'inactive', 'archived']);
    });

    it('accepts a backed enum as the status', function () {
        // Act
        $label = registry()->label('lifecycle_test_periods', LifecycleTestPeriodStatus::Locked, 'en');

        // Assert
        expect($label)->toBe('Locked');
    });

    it('returns the code and a neutral tone for an unknown status', function () {
        // Act
        $label = registry()->label('lifecycle_test_periods', 'nonsense', 'en');
        $tone = registry()->tone('lifecycle_test_periods', 'nonsense');

        // Assert
        expect($label)->toBe('nonsense')
            ->and($tone)->toBe('neutral');
    });
});

describe('transitions', function () {
    it('allows a configured transition', function () {
        // Act
        $allowed = registry()->canTransition('lifecycle_test_periods', 'open', 'collecting');

        // Assert
        expect($allowed)->toBeTrue();
    });

    it('refuses a transition that is not configured where transitions are enforced', function () {
        // Act
        $allowed = registry()->canTransition('lifecycle_test_periods', LifecycleTestPeriodStatus::Open, LifecycleTestPeriodStatus::Locked);

        // Assert
        expect($allowed)->toBeFalse();
    });

    it('allows any defined status where transitions are not enforced', function () {
        // Act
        $allowed = registry()->canTransition('lifecycle_test_items', 'active', 'archived');

        // Assert
        expect($allowed)->toBeTrue();
    });

    it('refuses a status that does not exist', function () {
        // Act
        $allowed = registry()->canTransition('lifecycle_test_items', 'active', 'nonsense');

        // Assert
        expect($allowed)->toBeFalse();
    });

    it('agrees with the database for every pair of statuses', function () {
        // Arrange
        $codes = array_keys(registry()->statusesFor('lifecycle_test_periods'));

        // Act
        $registryAnswers = [];
        $databaseAnswers = [];
        foreach ($codes as $from) {
            foreach ($codes as $to) {
                if ($from === $to) {
                    continue;
                }
                $registryAnswers["{$from}->{$to}"] = registry()->canTransition('lifecycle_test_periods', $from, $to);
                $databaseAnswers["{$from}->{$to}"] = DB::table('status_transitions')
                    ->where(['entity_name' => 'lifecycle_test_periods', 'from_code' => $from, 'to_code' => $to, 'is_active' => true])
                    ->exists();
            }
        }

        // Assert
        expect($registryAnswers)->toBe($databaseAnswers);
    });

    it('tells whether a transition asks for a reason', function () {
        // Arrange
        DB::table('status_transitions')
            ->where(['entity_name' => 'lifecycle_test_periods', 'from_code' => 'collecting', 'to_code' => 'locked'])
            ->update(['requires_reason' => true]);
        registry()->flush();

        // Act
        $lockNeedsReason = registry()->requiresReason('lifecycle_test_periods', 'collecting', 'locked');
        $collectNeedsReason = registry()->requiresReason('lifecycle_test_periods', 'open', 'collecting');

        // Assert
        expect($lockNeedsReason)->toBeTrue()
            ->and($collectNeedsReason)->toBeFalse();
    });
});

describe('caching', function () {
    it('serves the next request from the cache without querying the catalogue', function () {
        // Arrange
        registry()->label('lifecycle_test_periods', 'open');
        DB::enableQueryLog();

        // Act
        freshRegistry()->label('lifecycle_test_periods', 'open');

        // Assert
        $catalogueQueries = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'status_definitions'));
        expect($catalogueQueries)->toBeEmpty();
    });

    it('keeps serving the cached catalogue until it is flushed', function () {
        // Arrange
        registry()->label('lifecycle_test_periods', 'open');
        addPeriodStatus('on_hold', 'On hold');

        // Act
        $label = freshRegistry()->label('lifecycle_test_periods', 'on_hold', 'en');

        // Assert
        expect($label)->toBe('on_hold');
    });

    it('picks up a new status after flush()', function () {
        // Arrange
        registry()->label('lifecycle_test_periods', 'open');
        addPeriodStatus('on_hold', 'On hold');

        // Act
        registry()->flush();

        // Assert
        expect(freshRegistry()->label('lifecycle_test_periods', 'on_hold', 'en'))->toBe('On hold');
    });

    it('is flushed automatically after migrations run', function () {
        // Arrange
        registry()->label('lifecycle_test_periods', 'open');
        addPeriodStatus('on_hold', 'On hold');

        // Act
        event(new MigrationsEnded('up'));

        // Assert
        expect(freshRegistry()->label('lifecycle_test_periods', 'on_hold', 'en'))->toBe('On hold');
    });
});

describe('ui source', function () {
    it('shares every status as label and tone per entity in the given locale', function () {
        // Act
        $shared = registry()->toArray('bn');

        // Assert
        expect($shared['*']['active'])->toBe(['label' => 'সক্রিয়', 'tone' => 'success'])
            ->and($shared['lifecycle_test_periods'])->toBe([
                'open' => ['label' => 'Open', 'tone' => 'info'],
                'collecting' => ['label' => 'Collecting', 'tone' => 'info'],
                'locked' => ['label' => 'Locked', 'tone' => 'muted'],
            ]);
    });
});
