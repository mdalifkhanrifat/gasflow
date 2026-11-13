<?php

namespace App\Shared\Lifecycle;

/**
 * What kind of record a table holds. Documents intent in lifecycle_entities
 * and drives the default delete policy (08-record-lifecycle-standard.md §2.2).
 */
enum RecordClass: string
{
    case Master = 'master';
    case Configuration = 'configuration';
    case Pivot = 'pivot';
    case Workflow = 'workflow';
    case History = 'history';
    case Financial = 'financial';
    case Log = 'log';
}
