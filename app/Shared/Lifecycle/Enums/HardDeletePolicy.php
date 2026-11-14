<?php

namespace App\Shared\Lifecycle\Enums;

/**
 * Whether a DELETE may physically remove rows.
 */
enum HardDeletePolicy: string
{
    /** Pivots and expired technical rows only. */
    case Allowed = 'allowed';

    /** The row is kept; DELETE becomes a soft delete or is refused. */
    case Forbidden = 'forbidden';
}
