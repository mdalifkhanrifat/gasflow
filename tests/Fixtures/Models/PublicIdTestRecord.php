<?php

namespace Tests\Fixtures\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only record with a BIGINT primary key and a ULID public id.
 */
#[Table('public_id_test_records')]
#[Fillable(['name', 'public_id'])]
class PublicIdTestRecord extends Model
{
    use HasPublicId;
}
