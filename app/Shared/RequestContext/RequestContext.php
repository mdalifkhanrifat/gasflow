<?php

namespace App\Shared\RequestContext;

use App\Shared\Lifecycle\DatabaseContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who / which device / where (08-record-lifecycle-standard.md §9).
 *
 * Adds the client type, device kind, IP with city and country, and the
 * consented GPS fix to the DatabaseContext, so the actor-stamp and activity
 * triggers store them with every change. Must run before LifecycleContext.
 *
 * Header values are client-reported and only describe the change; they never
 * grant anything. Organization location policy checks come with tenancy.
 */
final class RequestContext
{
    /** X-Client values a request may claim. */
    public const array CLIENT_TYPES = ['browser', 'pwa', 'flutter'];

    public const string DEVICE_ATTRIBUTE = 'request_context.device';

    public function __construct(
        private readonly DatabaseContext $context,
        private readonly DeviceDetection $deviceDetection,
        private readonly IpLocator $ipLocator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = $this->deviceDetection->detect((string) $request->userAgent(), $request->server->all());
        $request->attributes->set(self::DEVICE_ATTRIBUTE, $device);

        $ip = $request->ip();
        $location = $ip === null ? null : $this->ipLocator->locate($ip);
        $fix = GeoFix::fromHeader($request->header('X-Geo'), Date::now());

        $this->context->merge([
            'client_type' => $this->clientType($request),
            'device_kind' => $device->kind,
            'ip' => $ip,
            'ip_country' => $location?->countryCode,
            'ip_city' => $location?->city,
            'geo_lat' => $fix?->latitude,
            'geo_lon' => $fix?->longitude,
            'geo_accuracy_m' => $fix?->accuracyMeters,
            'geo_source' => match (true) {
                $fix !== null => 'gps',
                $location?->city !== null => 'ip',
                default => 'none',
            },
        ]);

        return $next($request);
    }

    private function clientType(Request $request): string
    {
        $claimed = strtolower((string) $request->header('X-Client'));

        return in_array($claimed, self::CLIENT_TYPES, true) ? $claimed : 'browser';
    }
}
