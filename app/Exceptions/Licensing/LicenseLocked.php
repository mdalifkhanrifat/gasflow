<?php

namespace App\Exceptions\Licensing;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Work refused because the installation has no valid license (design 09 §3).
 * Rendered as 423 Locked. The message does not say why the license failed;
 * the license screen (Phase 3) shows that to the people allowed to see it.
 */
final class LicenseLocked extends HttpException
{
    public const string MESSAGE = 'GasFlow is in read-only mode because this installation has no valid license. Data can still be read and exported.';

    public function __construct()
    {
        parent::__construct(Response::HTTP_LOCKED, self::MESSAGE);
    }
}
