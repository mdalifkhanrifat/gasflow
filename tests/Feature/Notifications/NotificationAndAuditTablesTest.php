<?php

/**
 * Migration group 14, part 1: notification templates, notifications, audit
 * logs and idempotency keys (design 02 §9, V7.7, V7.8, V7.10, V7.12, V7.13).
 *
 * Ported from gasflow-design-v7/tests: i18n-mobile-tests.sql 32,
 * override-tests.sql 52.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TestRecords;

/**
 * A platform template unless the row says otherwise.
 *
 * @param  array<string, mixed>  $overrides
 */
function notificationTemplate(array $overrides = []): int
{
    return (int) DB::table('notification_templates')->insertGetId($overrides + [
        'event_code' => 'invoice.issued', 'channel' => 'sms', 'locale' => 'bn',
        'body' => 'আপনার {month} মাসের গ্যাস বিল ৳{amount}',
    ]);
}

/**
 * An SMS to a customer of a building unless the row says otherwise.
 *
 * @param  array<string, mixed>  $overrides
 */
function notification(array $overrides = []): int
{
    $organizationId = $overrides['organization_id'] ?? TestRecords::organization();
    $scope = array_key_exists('organization_id', $overrides) ? [] : ['building_id' => TestRecords::building($organizationId)];
    $recipient = array_key_exists('user_id', $overrides) || array_key_exists('customer_id', $overrides)
        ? [] : ['customer_id' => TestRecords::customer($organizationId)];

    return (int) DB::table('notifications')->insertGetId($overrides + $scope + $recipient + [
        'channel' => 'sms', 'event_code' => 'invoice.issued', 'message' => 'Your bill is ready',
    ]);
}

/**
 * Moves a notification through the given statuses.
 *
 * @param  list<array<string, mixed>|string>  $steps
 */
function moveNotification(int $id, array $steps): void
{
    foreach ($steps as $step) {
        DB::table('notifications')->where('id', $id)->update(is_string($step) ? ['status' => $step] : $step);
    }
}

/**
 * @param  array<string, mixed>  $overrides
 */
function auditLog(array $overrides = []): int
{
    return (int) DB::table('audit_logs')->insertGetId($overrides + [
        'action' => 'invoice.issued', 'subject_type' => 'invoice', 'subject_id' => 1,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function idempotencyKey(array $overrides = []): int
{
    return (int) DB::table('idempotency_keys')->insertGetId($overrides + [
        'organization_id' => TestRecords::organization(), 'key' => (string) Str::uuid(),
        'operation' => 'payments.create', 'request_hash' => str_repeat('a', 64),
    ]);
}

describe('lifecycle registration', function () {
    it('registers each table with the policies of the design', function (string $table, array $expected) {
        // Arrange: migration 2026_11_20_000077 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', $table)->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy, $entity->enforce_transitions, $entity->default_status])
            ->toBe($expected);
    })->with([
        ['notification_templates', ['notifications', 'configuration', 'allowed', 'forbidden', false, 'active']],
        ['notifications', ['notifications', 'log', 'protected', 'forbidden', true, 'queued']],
        ['audit_logs', ['audit', 'log', 'protected', 'forbidden', false, 'recorded']],
        ['idempotency_keys', ['shared', 'log', 'protected', 'allowed', false, 'active']],
    ]);

    it('keeps the logs out of the data change trail', function (Closure $insert, string $table) {
        // Arrange
        $id = $insert();

        // Act
        $logged = DB::table('data_change_logs')->where(['entity_name' => $table, 'record_key' => (string) $id])->exists();

        // Assert
        expect($logged)->toBeFalse();
    })->with([
        'notifications' => [fn () => notification(), 'notifications'],
        'audit logs' => [fn () => auditLog(), 'audit_logs'],
        'idempotency keys' => [fn () => idempotencyKey(), 'idempotency_keys'],
    ]);

    it('keeps template changes in the data change trail', function () {
        // Arrange
        $id = notificationTemplate();

        // Act
        $logged = DB::table('data_change_logs')->where(['entity_name' => 'notification_templates', 'record_key' => (string) $id])->exists();

        // Assert
        expect($logged)->toBeTrue();
    });

    it('lets a template be hidden', function () {
        // Arrange
        $id = notificationTemplate();

        // Act
        DB::table('notification_templates')->where('id', $id)->delete();

        // Assert
        expect(DB::table('notification_templates')->where('id', $id)->value('is_deleted'))->toBeTrue();
    });

    it('never lets a notification or audit entry be deleted', function (Closure $insert, string $table) {
        // Arrange
        $id = $insert();

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))
            ->toThrow(QueryException::class, "Records in {$table} are protected");
    })->with([
        'notification' => [fn () => notification(), 'notifications'],
        'audit entry' => [fn () => auditLog(), 'audit_logs'],
    ]);

    it('lets an idempotency key be removed for good', function () {
        // Arrange
        $id = idempotencyKey();

        // Act
        DB::table('idempotency_keys')->where('id', $id)->delete();

        // Assert
        expect(DB::table('idempotency_keys')->where('id', $id)->exists())->toBeFalse();
    });
});

describe('audit logs under the maintenance override', function () {
    beforeEach(function () {
        $this->operator = DB::connection('pgsql_maintenance');
        $this->operator->beginTransaction();
        $this->auditId = $this->operator->table('audit_logs')->insertGetId(['action' => 'test', 'subject_type' => 'x', 'subject_id' => 1]);
        $this->operator->select(
            "select set_config('app.maintenance_override', 'on', true), set_config('app.lifecycle_reason', ?, true)",
            ['try to hide audit'],
        );
    });

    afterEach(function () {
        $this->operator->rollBack();
    });

    it('cannot be hidden even with the override (design 52)', function () {
        // Arrange: an audit entry and the override switched on

        // Act & Assert
        expect(fn () => $this->operator->table('audit_logs')->where('id', $this->auditId)->delete())
            ->toThrow(QueryException::class, 'Records in audit_logs are protected');
    });

    it('is the only one of the new logs the override may not touch', function () {
        // Arrange: migration 2026_11_20_000077 has run

        // Act
        $allowed = DB::table('lifecycle_entities')
            ->whereIn('entity_name', ['notification_templates', 'notifications', 'audit_logs', 'idempotency_keys'])
            ->orderBy('entity_name')->pluck('override_allowed', 'entity_name')->all();

        // Assert
        expect($allowed)->toBe(['audit_logs' => false, 'idempotency_keys' => true, 'notification_templates' => true, 'notifications' => true]);
    });
});

describe('notification templates', function () {
    it('allow one platform template per event, channel, locale and version (design 32)', function () {
        // Arrange
        notificationTemplate();

        // Act & Assert
        expect(fn () => notificationTemplate(['body' => 'dup']))->toThrow(QueryException::class, 'uq_notification_templates_scope');
    });

    it('let an organization override a platform template', function () {
        // Arrange
        notificationTemplate();

        // Act
        $id = notificationTemplate(['organization_id' => TestRecords::organization()]);

        // Assert
        expect($id)->toBeGreaterThan(0);
    });

    it('allow a new version of the same template', function () {
        // Arrange
        notificationTemplate();

        // Act
        $id = notificationTemplate(['version_no' => 2]);

        // Assert
        expect(DB::table('notification_templates')->where('id', $id)->value('version_no'))->toBe(2);
    });

    it('refuse a wrong channel, version or locale', function (array $row, string $error) {
        // Arrange: the row of the dataset

        // Act & Assert
        expect(fn () => notificationTemplate($row))->toThrow(QueryException::class, $error);
    })->with([
        'channel' => [['channel' => 'fax'], 'notification_templates_channel_check'],
        'version' => [['version_no' => 0], 'notification_templates_version_no_check'],
        'locale' => [['locale' => 'fr'], 'notification_templates_locale_foreign'],
    ]);
});

describe('notifications', function () {
    it('start queued', function () {
        // Arrange
        $id = notification();

        // Act
        $status = DB::table('notifications')->where('id', $id)->value('status');

        // Assert
        expect($status)->toBe('queued');
    });

    it('belong to an organization or a building, never both or neither', function (Closure $scope) {
        // Arrange
        $row = ['channel' => 'sms', 'event_code' => 'invoice.issued', 'message' => 'x', 'customer_id' => TestRecords::customer()] + $scope();

        // Act & Assert
        expect(fn () => DB::table('notifications')->insert($row))->toThrow(QueryException::class, 'ck_notifications_scope_xor');
    })->with([
        'both' => [fn () => ['organization_id' => TestRecords::organization(), 'building_id' => TestRecords::building()]],
        'neither' => [fn () => []],
    ]);

    it('go through a known channel only', function () {
        // Arrange
        $row = ['channel' => 'fax'];

        // Act & Assert
        expect(fn () => notification($row))->toThrow(QueryException::class, 'notifications_channel_check');
    });

    it('take only the statuses of the design (failed retried since D51), as initial, terminal and neutral to is_active', function () {
        // Arrange: migration 2026_11_20_000077 has run

        // Act
        $statuses = DB::table('status_definitions')->whereIn('entity_name', ['notifications', 'audit_logs'])
            ->orderBy('entity_name', 'desc')->orderBy('sort_order')
            ->get(['entity_name', 'code', 'implies_active', 'is_initial', 'is_terminal'])
            ->map(fn ($s) => [$s->entity_name, $s->code, $s->implies_active, $s->is_initial, $s->is_terminal])->all();

        // Assert
        expect($statuses)->toBe([
            ['notifications', 'queued', null, true, false],
            ['notifications', 'sending', null, false, false],
            ['notifications', 'sent', null, false, false],
            ['notifications', 'delivered', null, false, true],
            ['notifications', 'failed', null, false, false],
            ['notifications', 'cancelled', null, false, true],
            ['audit_logs', 'recorded', null, true, true],
        ]);
    });

    it('stay active when they are sent', function () {
        // Arrange
        $id = notification();

        // Act
        moveNotification($id, ['sending', ['status' => 'sent', 'sent_at' => now()]]);

        // Assert
        expect(DB::table('notifications')->where('id', $id)->value('is_active'))->toBeTrue();
    });

    it('refuse a status outside the catalogue', function () {
        // Arrange
        $id = notification();

        // Act & Assert
        expect(fn () => DB::table('notifications')->where('id', $id)->update(['status' => 'read']))
            ->toThrow(QueryException::class, 'Status "read" is not defined for notifications');
    });

    it('label every status in Bangla', function () {
        // Arrange: migration 2026_11_20_000077 has run

        // Act
        $missing = DB::table('status_definitions')->whereIn('entity_name', ['notifications', 'audit_logs'])
            ->whereRaw("NOT jsonb_exists(translations, 'bn')")->count();

        // Assert
        expect($missing)->toBe(0);
    });
});

describe('audit logs', function () {
    it('are recorded as they are written', function () {
        // Arrange
        $id = auditLog(['old_values' => '{"status":"draft"}', 'new_values' => '{"status":"issued"}', 'ip_address' => '203.0.113.7']);

        // Act
        $row = DB::table('audit_logs')->where('id', $id)->first();

        // Assert
        expect([$row->status, $row->ip_address])->toBe(['recorded', '203.0.113.7']);
    });
});

describe('idempotency keys', function () {
    it('are unique per organization, key and operation', function () {
        // Arrange
        $organizationId = TestRecords::organization();
        $key = (string) Str::uuid();
        idempotencyKey(['organization_id' => $organizationId, 'key' => $key]);

        // Act & Assert
        expect(fn () => idempotencyKey(['organization_id' => $organizationId, 'key' => $key]))
            ->toThrow(QueryException::class, 'idempotency_keys_organization_id_key_operation_unique');
    });

    it('allow the same key for another operation', function () {
        // Arrange
        $organizationId = TestRecords::organization();
        $key = (string) Str::uuid();
        idempotencyKey(['organization_id' => $organizationId, 'key' => $key]);

        // Act
        $id = idempotencyKey(['organization_id' => $organizationId, 'key' => $key, 'operation' => 'refunds.create']);

        // Assert
        expect($id)->toBeGreaterThan(0);
    });
});

describe('audit log integrity (D51)', function () {
    it('cannot be changed by the app', function () {
        // Arrange
        $id = auditLog();

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('id', $id)->update(['action' => 'nothing'])))
            ->toThrow(QueryException::class, 'permission denied for table audit_logs');
    });

    it('cannot be changed by a maintenance member either', function () {
        // Arrange
        $operator = DB::connection('pgsql_maintenance');
        $operator->beginTransaction();
        $id = $operator->table('audit_logs')->insertGetId(['action' => 'x', 'subject_type' => 'x', 'subject_id' => 1]);

        // Act & Assert
        try {
            expect(fn () => $operator->table('audit_logs')->where('id', $id)->update(['action' => 'y']))
                ->toThrow(QueryException::class, 'permission denied for table audit_logs');
        } finally {
            $operator->rollBack();
        }
    });

    it('names an organization for a building entry', function () {
        // Arrange
        $row = ['building_id' => TestRecords::building()];

        // Act & Assert
        expect(fn () => auditLog($row))->toThrow(QueryException::class, 'ck_audit_logs_building_organization');
    });

    it('refuses a building entry of an organization without access', function () {
        // Arrange
        $organizationId = TestRecords::organization();
        $buildingId = TestRecords::building();

        // Act & Assert
        expect(fn () => auditLog(['organization_id' => $organizationId, 'building_id' => $buildingId]))
            ->toThrow(QueryException::class, "Organization {$organizationId} has no access to building {$buildingId}");
    });

    it('accepts a building entry of the building\'s organization and a platform entry', function () {
        // Arrange
        $organizationId = TestRecords::organization();
        $buildingId = TestRecords::building($organizationId);

        // Act
        $ids = [auditLog(['organization_id' => $organizationId, 'building_id' => $buildingId]), auditLog()];

        // Assert
        expect(DB::table('audit_logs')->whereIn('id', $ids)->count())->toBe(2);
    });
});

describe('notification templates keep what was sent (D51)', function () {
    it('refuse a change of the text once written', function () {
        // Arrange
        $id = notificationTemplate();

        // Act & Assert
        expect(fn () => DB::table('notification_templates')->where('id', $id)->update(['body' => 'rewritten']))
            ->toThrow(QueryException::class, "notification_templates {$id} is active: body can no longer change");
    });

    it('need a subject for email', function () {
        // Arrange
        $row = ['channel' => 'email'];

        // Act & Assert
        expect(fn () => notificationTemplate($row))->toThrow(QueryException::class, 'ck_notification_templates_email_subject');
    });
});

describe('notification recipients (D51)', function () {
    it('are exactly one user or customer', function (Closure $recipient) {
        // Arrange
        $organizationId = TestRecords::organization();
        $row = $recipient($organizationId);

        // Act & Assert
        expect(fn () => notification(['organization_id' => $organizationId] + $row))->toThrow(QueryException::class, 'ck_notifications_one_recipient');
    })->with([
        'none' => [fn () => ['user_id' => null, 'customer_id' => null]],
        'both' => [fn (int $org) => ['user_id' => tap(TestRecords::user(), fn (int $user) => TestRecords::membership($org, $user)), 'customer_id' => TestRecords::customer($org)]],
    ]);

    it('need a user for in-app and push', function () {
        // Arrange
        $row = ['channel' => 'push'];

        // Act & Assert
        expect(fn () => notification($row))->toThrow(QueryException::class, 'ck_notifications_user_channel');
    });

    it('refuse a customer of an organization without access to the building', function () {
        // Arrange
        $customerId = TestRecords::customer(TestRecords::organization());

        // Act & Assert
        expect(fn () => notification(['customer_id' => $customerId]))
            ->toThrow(QueryException::class, "Customer {$customerId} is not a customer of this notification's organization or building");
    });

    it('refuse a customer of another organization', function () {
        // Arrange
        $customerId = TestRecords::customer(TestRecords::organization());

        // Act & Assert
        expect(fn () => notification(['organization_id' => TestRecords::organization(), 'customer_id' => $customerId]))
            ->toThrow(QueryException::class, "Customer {$customerId} is not a customer");
    });

    it('refuse a user who is not a member', function (bool $building) {
        // Arrange
        $userId = TestRecords::user();
        $scope = $building ? [] : ['organization_id' => TestRecords::organization()];

        // Act & Assert
        expect(fn () => notification($scope + ['user_id' => $userId, 'channel' => 'in_app']))
            ->toThrow(QueryException::class, "User {$userId} is not a member of this notification's organization or building");
    })->with(['building' => [true], 'organization' => [false]]);

    it('refuse a suspended member', function () {
        // Arrange
        $organizationId = TestRecords::organization();
        $userId = TestRecords::user();
        $membershipId = TestRecords::membership($organizationId, $userId);
        DB::table('organization_memberships')->where('id', $membershipId)->update(['status' => 'suspended']);

        // Act & Assert
        expect(fn () => notification(['organization_id' => $organizationId, 'user_id' => $userId, 'channel' => 'in_app']))
            ->toThrow(QueryException::class, "User {$userId} is not a member");
    });

    it('accept a member of the organization and a user of the building', function () {
        // Arrange
        $organizationId = TestRecords::organization();
        $userId = TestRecords::user();
        TestRecords::membership($organizationId, $userId);

        // Act
        $ids = [
            notification(['organization_id' => $organizationId, 'user_id' => $userId, 'channel' => 'in_app']),
            notification(['building_id' => TestRecords::building($organizationId), 'user_id' => $userId, 'channel' => 'push']),
        ];

        // Assert
        expect(DB::table('notifications')->whereIn('id', $ids)->count())->toBe(2);
    });
});

describe('notification templates in use (D51)', function () {
    it('must match the notification\'s event, channel and locale', function () {
        // Arrange
        $templateId = notificationTemplate();

        // Act & Assert
        expect(fn () => notification(['notification_template_id' => $templateId, 'channel' => 'email', 'locale' => 'bn']))
            ->toThrow(QueryException::class, 'fk_notifications_template_content');
    });

    it('need the locale on the notification', function () {
        // Arrange
        $templateId = notificationTemplate();

        // Act & Assert
        expect(fn () => notification(['notification_template_id' => $templateId]))
            ->toThrow(QueryException::class, 'ck_notifications_template_locale');
    });

    it('may not be another organization\'s', function () {
        // Arrange
        $templateId = notificationTemplate(['organization_id' => TestRecords::organization()]);

        // Act & Assert
        expect(fn () => notification(['notification_template_id' => $templateId, 'locale' => 'bn']))
            ->toThrow(QueryException::class, "Template {$templateId} belongs to organization");
    });

    it('may be a platform template or the organization\'s own', function () {
        // Arrange
        $organizationId = TestRecords::organization();
        $own = notificationTemplate(['organization_id' => $organizationId]);
        $platform = notificationTemplate();

        // Act
        $ids = [
            notification(['organization_id' => $organizationId, 'notification_template_id' => $own, 'locale' => 'bn']),
            notification(['notification_template_id' => $platform, 'locale' => 'bn']),
        ];

        // Assert
        expect(DB::table('notifications')->whereIn('id', $ids)->count())->toBe(2);
    });
});

describe('notification statuses (D51)', function () {
    it('follow queued, sending, sent, delivered', function () {
        // Arrange
        $id = notification();

        // Act
        moveNotification($id, ['sending', ['status' => 'sent', 'sent_at' => now()], 'delivered']);

        // Assert
        expect(DB::table('notifications')->where('id', $id)->value('status'))->toBe('delivered');
    });

    it('refuse a jump back from delivered', function () {
        // Arrange
        $id = notification();
        moveNotification($id, ['sending', ['status' => 'sent', 'sent_at' => now()], 'delivered']);

        // Act & Assert
        expect(fn () => DB::table('notifications')->where('id', $id)->update(['status' => 'queued', 'sent_at' => null]))
            ->toThrow(QueryException::class, 'Status transition delivered -> queued is not allowed for notifications');
    });

    it('retry a failed notification', function () {
        // Arrange
        $id = notification();
        moveNotification($id, [['status' => 'failed', 'error_message' => 'Gateway timeout']]);

        // Act
        moveNotification($id, ['queued']);

        // Assert
        expect(DB::table('notifications')->where('id', $id)->value('status'))->toBe('queued');
    });

    it('tie the sent time to sent and delivered', function (Closure $write) {
        // Arrange
        $id = notification();

        // Act & Assert
        expect(fn () => $write($id))->toThrow(QueryException::class, 'ck_notifications_sent_at');
    })->with([
        'sent without time' => [fn (int $id) => moveNotification($id, ['sending', 'sent'])],
        'queued with time' => [fn (int $id) => DB::table('notifications')->where('id', $id)->update(['sent_at' => now()])],
    ]);

    it('need an error message to fail', function () {
        // Arrange
        $id = notification();

        // Act & Assert
        expect(fn () => moveNotification($id, ['failed']))->toThrow(QueryException::class, 'ck_notifications_failed_error');
    });

    it('freeze the message once it leaves the queue', function () {
        // Arrange
        $id = notification();
        moveNotification($id, ['sending']);

        // Act & Assert
        expect(fn () => DB::table('notifications')->where('id', $id)->update(['message' => 'changed']))
            ->toThrow(QueryException::class, "notifications {$id} is sending: message can no longer change");
    });

    it('let the message change while queued', function () {
        // Arrange
        $id = notification();

        // Act
        DB::table('notifications')->where('id', $id)->update(['message' => 'changed']);

        // Assert
        expect(DB::table('notifications')->where('id', $id)->value('message'))->toBe('changed');
    });
});

describe('idempotency key integrity (D51)', function () {
    it('are unique for platform keys too', function () {
        // Arrange
        $key = (string) Str::uuid();
        idempotencyKey(['organization_id' => null, 'key' => $key]);

        // Act & Assert
        expect(fn () => idempotencyKey(['organization_id' => null, 'key' => $key]))
            ->toThrow(QueryException::class, 'idempotency_keys_organization_id_key_operation_unique');
    });

    it('keep the request they were made for', function () {
        // Arrange
        $id = idempotencyKey();

        // Act & Assert
        expect(fn () => DB::table('idempotency_keys')->where('id', $id)->update(['request_hash' => str_repeat('c', 64)]))
            ->toThrow(QueryException::class, "idempotency_keys {$id} is active: request_hash can no longer change");
    });

    it('store the response once', function () {
        // Arrange
        $id = idempotencyKey();
        DB::table('idempotency_keys')->where('id', $id)->update(['response_code' => 201, 'response_body' => '{"id":7}']);

        // Act & Assert
        expect(fn () => DB::table('idempotency_keys')->where('id', $id)->update(['response_code' => 500, 'response_body' => '{}']))
            ->toThrow(QueryException::class, "idempotency_keys {$id}: response_code, response_body is written once");
    });

    it('refuse a wrong hash, half a response or an expiry in the past', function (array $row, string $error) {
        // Arrange: the row of the dataset

        // Act & Assert
        expect(fn () => idempotencyKey($row))->toThrow(QueryException::class, $error);
    })->with([
        'hash' => [['request_hash' => str_repeat('z', 64)], 'ck_idempotency_keys_request_hash'],
        'body without code' => [['response_body' => '{}'], 'ck_idempotency_keys_response'],
        'code without body' => [['response_code' => 204], 'ck_idempotency_keys_response'],
        'expiry' => [['expires_at' => '2020-01-01 00:00:00+00'], 'ck_idempotency_keys_expires_at'],
    ]);
});
