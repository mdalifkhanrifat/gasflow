<?php

namespace App\Shared\Lifecycle\Http\Middleware;

use App\Shared\Lifecycle\DatabaseContext;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs every web/API request in one database transaction with the request
 * context applied (01-final-architecture.md §13.5):
 *
 *     BEGIN; set_config(app.user_id, app.organization_id, ...); ... COMMIT
 *
 * The values are transaction-local, so they end with the request. A request
 * that ends in an exception (also one already rendered into an error response,
 * e.g. a validation error) is rolled back as a whole.
 *
 * Organization, device and location values are added to DatabaseContext by
 * the tenancy and request-context middleware that run before this one.
 */
final class LifecycleContext
{
    public function __construct(
        private readonly DatabaseContext $context,
        private readonly ConnectionInterface $connection,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->set('user_id', $request->user()?->getAuthIdentifier());

        $this->connection->beginTransaction();

        try {
            $this->context->apply();
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        // Laravel renders exceptions into responses inside the pipeline.
        if (property_exists($response, 'exception') && $response->exception !== null) {
            $this->connection->rollBack();
        } else {
            $this->connection->commit();
        }

        return $response;
    }
}
