<?php

namespace App\Http\Middleware;

use App\Services\Lifecycle\DatabaseContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for every request (design 01 §13.5, 08 §9).
 *
 * One time-ordered UUID (v7) ties together everything a request causes:
 * - log lines and queued jobs, through Laravel's Context (jobs carry it along)
 * - the activity trail, through app.request_id (data_change_logs.request_id)
 * - the X-Request-Id response header, so an error screen can show the id
 *   a user quotes to support
 *
 * The id is always made here. A client-sent X-Request-Id is ignored, because a
 * forged one could tie unrelated changes together in the audit trail.
 * Global middleware, so refused and failed requests carry the id as well.
 */
final readonly class AssignRequestId
{
    public const string HEADER = 'X-Request-Id';

    public function __construct(private DatabaseContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid7();

        Context::add('request_id', $id);
        $this->context->set('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
