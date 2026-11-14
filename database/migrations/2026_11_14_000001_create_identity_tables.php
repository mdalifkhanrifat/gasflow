<?php

use App\Enums\Lifecycle\RecordClass;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 2: identity (design 02 §1, V7.7, V7.13).
 *
 * Replaces Laravel's default users / password_reset_tokens / sessions tables
 * with the design's shape and adds Sanctum's personal_access_tokens (D23).
 * Only `users` joins the lifecycle standard; the other three are framework
 * tables, as in the design.
 *
 * user_devices and access_sessions come later, with the tables they need
 * (supported_locales, organizations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->char('public_id', 26)->unique();
            $table->string('name', 160);
            $table->string('email', 190)->unique();
            $table->string('phone', 30)->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('status', 24)->default('active');
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->boolean('mfa_enabled')->default(false);
            $table->timestampTz('location_consent_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 190)->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('payload');
            $table->integer('last_activity')->index('idx_sessions_last_activity');
        });
        DB::statement('CREATE INDEX idx_sessions_user_id ON sessions(user_id) WHERE user_id IS NOT NULL');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->morphs('tokenable', 'idx_personal_access_tokens_tokenable');
            $table->string('name');
            $table->char('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('CREATE INDEX idx_personal_access_tokens_expires_at ON personal_access_tokens(expires_at) WHERE expires_at IS NOT NULL');

        LifecycleSchema::attach('users', module: 'identity', class: RecordClass::Master);

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('users', 'active',    'Active',    'success', true,  true,  false, 10, '{"bn":{"label":"সক্রিয়"}}'),
                   ('users', 'invited',   'Invited',   'info',    NULL,  true,  false, 20, '{"bn":{"label":"আমন্ত্রিত"}}'),
                   ('users', 'suspended', 'Suspended', 'warning', false, false, false, 30, '{"bn":{"label":"স্থগিত"}}'),
                   ('users', 'closed',    'Closed',    'muted',   false, false, true,  40, '{"bn":{"label":"বন্ধ"}}');
            -- secrets never enter the activity trail
            UPDATE lifecycle_entities SET redacted_columns = '{password,remember_token}' WHERE entity_name = 'users';
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_definitions WHERE entity_name = 'users';
            DELETE FROM lifecycle_entities WHERE entity_name = 'users';
            SQL);

        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
