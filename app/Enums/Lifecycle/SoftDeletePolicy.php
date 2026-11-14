<?php

namespace App\Enums\Lifecycle;

/**
 * Whether the app may hide rows (is_deleted = true).
 */
enum SoftDeletePolicy: string
{
    /** A plain DELETE becomes a soft delete automatically. */
    case Allowed = 'allowed';

    /** Money and history tables: only the audited backend maintenance override may hide rows. */
    case Protected = 'protected';
}
