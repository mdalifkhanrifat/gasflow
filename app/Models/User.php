<?php

namespace App\Models;

use App\Models\Concerns\HasLifecycle;
use App\Models\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * A person who signs in: platform staff, vendor and building staff,
 * caretakers (design 02 §1, identity group). Lives in the lifecycle standard
 * as a master record: statuses active / invited / suspended / closed, soft
 * delete only, and password / remember_token never reach the activity trail.
 * URLs and the API use public_id, never id.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $locale preferred language (supported_locales), else the organization's
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property bool $mfa_enabled
 * @property Carbon|null $location_consent_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasLifecycle, HasPublicId, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'location_consent_at' => 'datetime',
            'mfa_enabled' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
