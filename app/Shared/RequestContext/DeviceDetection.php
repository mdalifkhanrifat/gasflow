<?php

namespace App\Shared\RequestContext;

use DeviceDetector\ClientHints;
use DeviceDetector\DeviceDetector;

/**
 * User agent + client hints → desktop / mobile / tablet, OS and browser.
 * Laptops count as desktop (design 08 §9).
 */
final class DeviceDetection
{
    private const array KINDS = [
        'desktop' => Device::DESKTOP,
        'smartphone' => Device::MOBILE,
        'phablet' => Device::MOBILE,
        'feature phone' => Device::MOBILE,
        'tablet' => Device::TABLET,
    ];

    /**
     * @param  array<string, mixed>  $headers  server variables (HTTP_*), for client hints
     */
    public function detect(string $userAgent, array $headers = []): Device
    {
        $detector = new DeviceDetector($userAgent, ClientHints::factory($headers));
        $detector->discardBotInformation();
        $detector->parse();

        if ($detector->isBot()) {
            return new Device(Device::UNKNOWN, null, null);
        }

        $os = $detector->getOs('name');
        $browser = $detector->getClient('name');

        return new Device(
            kind: self::KINDS[$detector->getDeviceName()] ?? Device::UNKNOWN,
            os: is_string($os) && $os !== '' && $os !== DeviceDetector::UNKNOWN ? $os : null,
            browser: is_string($browser) && $browser !== '' && $browser !== DeviceDetector::UNKNOWN ? $browser : null,
        );
    }
}
