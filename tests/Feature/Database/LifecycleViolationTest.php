<?php

/**
 * Database lifecycle refusals (SQLSTATE P0001) become 422 validation errors
 * with a readable message; every other database error stays untouched.
 */

use App\Shared\Lifecycle\LifecycleViolation;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function captureQueryException(Closure $statement): QueryException
{
    try {
        $statement();
    } catch (QueryException $exception) {
        return $exception;
    }

    throw new LogicException('The statement was expected to fail.');
}

function refusedLedgerDelete(): QueryException
{
    $id = DB::table('lifecycle_test_ledgers')->insertGetId(['amount' => 100]);

    return captureQueryException(fn () => DB::table('lifecycle_test_ledgers')->where('id', $id)->delete());
}

describe('LifecycleViolation::fromQueryException()', function () {
    it('turns a lifecycle refusal into a readable violation', function () {
        // Arrange
        $exception = refusedLedgerDelete();

        // Act
        $violation = LifecycleViolation::fromQueryException($exception);

        // Assert
        expect($violation)->toBeInstanceOf(LifecycleViolation::class)
            ->and($violation->status)->toBe(422)
            ->and($violation->errors())->toBe([
                'lifecycle' => ['Records in lifecycle_test_ledgers are protected; the app cannot delete them (backend maintenance override only)'],
            ]);
    });

    it('ignores database errors that are not lifecycle refusals', function () {
        // Arrange
        $exception = captureQueryException(fn () => DB::table('table_that_does_not_exist')->count());

        // Act
        $violation = LifecycleViolation::fromQueryException($exception);

        // Assert
        expect($violation)->toBeNull();
    });
});

describe('exception handler', function () {
    it('answers a refused change with a 422 json error', function () {
        // Arrange
        $exception = refusedLedgerDelete();
        $request = Request::create('/api/v1/anything', 'DELETE', server: ['HTTP_ACCEPT' => 'application/json']);

        // Act
        $response = app(ExceptionHandler::class)->render($request, $exception);

        // Assert
        expect($response->getStatusCode())->toBe(422)
            ->and(json_decode($response->getContent(), true)['errors']['lifecycle'][0])
            ->toStartWith('Records in lifecycle_test_ledgers are protected');
    });

    it('leaves other database errors as server errors', function () {
        // Arrange
        $exception = captureQueryException(fn () => DB::table('table_that_does_not_exist')->count());
        $request = Request::create('/api/v1/anything', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);

        // Act
        $response = app(ExceptionHandler::class)->render($request, $exception);

        // Assert
        expect($response->getStatusCode())->toBe(500);
    });
});
