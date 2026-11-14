<?php

namespace App\Services\RequestContext;

/**
 * The device a request came from, as far as the user agent and client hints tell.
 */
final readonly class Device
{
    public const string DESKTOP = 'desktop';

    public const string MOBILE = 'mobile';

    public const string TABLET = 'tablet';

    public const string UNKNOWN = 'unknown';

    public function __construct(
        public string $kind,
        public ?string $os,
        public ?string $browser,
    ) {}
}
