<?php

namespace App\Modules\Licensing\Http\Middleware;

use App\Modules\Licensing\Exceptions\LicenseLocked;
use App\Modules\Licensing\LicenseState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only lock (design 09 §3, architecture §13.3). Without a valid license
 * the app still serves every read, so customers can see and export their data,
 * but refuses every write with 423. Nothing is deleted or hidden.
 *
 * Only the routes in licensing.locked_write_routes may write while locked
 * (login, logout, installing a new license). It runs in the web group, not
 * as global middleware, because it needs the matched route's name.
 */
final readonly class EnsureLicensed
{
    public function __construct(private LicenseState $license) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $this->license->allowsOperation() || $this->mayWriteWhileLocked($request)) {
            return $next($request);
        }

        throw new LicenseLocked;
    }

    private function mayWriteWhileLocked(Request $request): bool
    {
        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;

        return is_string($name) && in_array($name, (array) config('licensing.locked_write_routes', []), true);
    }
}
