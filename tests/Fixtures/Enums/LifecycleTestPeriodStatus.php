<?php

namespace Tests\Fixtures\Enums;

/**
 * Statuses of the test-only lifecycle_test_periods table (enforced transitions).
 */
enum LifecycleTestPeriodStatus: string
{
    case Open = 'open';
    case Collecting = 'collecting';
    case Locked = 'locked';
}
