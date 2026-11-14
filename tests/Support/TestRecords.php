<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rows for tests on tables that have no Eloquent model yet (organizations,
 * roles, access sessions). Each method inserts one row as the runtime role
 * and returns its id; the overrides replace any column.
 */
final class TestRecords
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function organization(array $overrides = []): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()),
            'code' => 'ORG-'.Str::upper(Str::random(8)),
            'name' => 'Northstar Gas Services',
            'organization_type' => 'gas_vendor',
            ...$overrides,
        ]);
    }

    public static function role(string $scope): int
    {
        return (int) DB::table('roles')->insertGetId([
            'code' => "{$scope}.role.".Str::lower(Str::random(8)),
            'name' => ucfirst($scope).' role',
            'scope' => $scope,
        ]);
    }

    public static function membership(int $organizationId, int $userId): int
    {
        return (int) DB::table('organization_memberships')->insertGetId([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'membership_role' => 'staff',
        ]);
    }

    /**
     * An active member of the organization: a new user's membership id.
     */
    public static function member(int $organizationId): int
    {
        return self::membership($organizationId, User::factory()->create()->id);
    }

    /**
     * A member of a new tenant organization placed in that tenant's new
     * building, as a building membership id.
     */
    public static function buildingMembership(): int
    {
        $tenantId = self::organization();

        return (int) DB::table('building_memberships')->insertGetId([
            'building_id' => self::building($tenantId),
            'organization_membership_id' => self::member($tenantId),
        ]);
    }

    /**
     * A building of the given tenant organization (a new organization when
     * none is given).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function building(?int $organizationId = null, array $overrides = []): int
    {
        return (int) DB::table('buildings')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()),
            'tenant_organization_id' => $organizationId ?? self::organization(),
            'code' => 'BLD-'.Str::upper(Str::random(8)),
            'name' => 'Lake View Tower',
            ...$overrides,
        ]);
    }

    /**
     * A gas vendor assignment of the building to a new vendor organization,
     * open-ended from the given day.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function vendorAssignment(int $buildingId, string $validFrom, array $overrides = []): int
    {
        return (int) DB::table('building_vendor_assignments')->insertGetId([
            'building_id' => $buildingId,
            'vendor_organization_id' => self::organization(),
            'valid_from' => $validFrom,
            ...$overrides,
        ]);
    }

    /**
     * An access grant to the building for the grantee organization, active
     * and open-ended from today unless overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function accessGrant(int $buildingId, int $granteeOrganizationId, array $overrides = []): int
    {
        return (int) DB::table('building_access_grants')->insertGetId([
            'building_id' => $buildingId,
            'building_owner_organization_id' => DB::table('buildings')->where('id', $buildingId)->value('tenant_organization_id'),
            'grantee_organization_id' => $granteeOrganizationId,
            'access_role' => 'gas_vendor',
            'valid_from' => today()->toDateString(),
            ...$overrides,
        ]);
    }

    /**
     * A monthly version of the given SaaS plan (a new plan when none is
     * given), open-ended from the given day.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function planVersion(?int $planId = null, string $effectiveFrom = '2026-01-01', array $overrides = []): int
    {
        $planId ??= (int) DB::table('saas_plans')->insertGetId([
            'code' => 'PLAN_'.Str::upper(Str::random(8)),
            'name' => 'Test plan',
        ]);

        return (int) DB::table('plan_versions')->insertGetId([
            'saas_plan_id' => $planId,
            'version_no' => (int) DB::table('plan_versions')->where('saas_plan_id', $planId)->max('version_no') + 1,
            'billing_interval' => 'monthly',
            'price' => 1500,
            'effective_from' => $effectiveFrom,
            ...$overrides,
        ]);
    }

    /**
     * A new building's subscription to a new plan version, starting now.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function buildingSubscription(array $overrides = []): int
    {
        return (int) DB::table('building_subscriptions')->insertGetId([
            'building_id' => self::building(),
            'plan_version_id' => self::planVersion(),
            'starts_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * An issued SaaS invoice of 1,500 BDT for a new building subscription.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function saasInvoice(array $overrides = []): int
    {
        return (int) DB::table('saas_invoices')->insertGetId([
            'building_subscription_id' => self::buildingSubscription(),
            'invoice_no' => 'SINV-'.Str::upper(Str::random(10)),
            'subtotal' => 1500,
            'total' => 1500,
            'issued_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * A flat in the given building (a new building when none is given).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function flat(?int $buildingId = null, array $overrides = []): int
    {
        return (int) DB::table('flats')->insertGetId([
            'building_id' => $buildingId ?? self::building(),
            'code' => 'F-'.Str::upper(Str::random(6)),
            ...$overrides,
        ]);
    }

    /**
     * A customer of the given tenant organization (a new organization when
     * none is given).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function customer(?int $organizationId = null, array $overrides = []): int
    {
        return (int) DB::table('customers')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()),
            'tenant_organization_id' => $organizationId ?? self::organization(),
            'customer_code' => 'C-'.Str::upper(Str::random(8)),
            'full_name' => 'Rahima Begum',
            ...$overrides,
        ]);
    }

    /**
     * A new customer's occupancy of the flat, open-ended from the given day.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function occupancy(int $flatId, string $validFrom, array $overrides = []): int
    {
        return (int) DB::table('flat_occupancies')->insertGetId([
            'flat_id' => $flatId,
            'customer_id' => self::customer(),
            'occupancy_type' => 'tenant',
            'valid_from' => $validFrom,
            ...$overrides,
        ]);
    }

    /**
     * A private file of a new building.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function attachment(array $overrides = []): int
    {
        return (int) DB::table('attachments')->insertGetId([
            'building_id' => self::building(),
            'object_key' => 'evidence/'.Str::lower(Str::random(16)).'.pdf',
            ...$overrides,
        ]);
    }

    /**
     * A sub-meter in the given building (a new building when none is given).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function meter(?int $buildingId = null, array $overrides = []): int
    {
        return (int) DB::table('meters')->insertGetId([
            'building_id' => $buildingId ?? self::building(),
            'meter_number' => 'M-'.Str::upper(Str::random(8)),
            'meter_type' => 'sub_meter',
            'measurement_unit' => 'm3',
            ...$overrides,
        ]);
    }

    /**
     * The meter placed in the flat (no flat: a main or common-area meter),
     * open-ended from the given day.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function meterAssignment(int $meterId, ?int $flatId, string $validFrom, array $overrides = []): int
    {
        return (int) DB::table('meter_assignments')->insertGetId([
            'meter_id' => $meterId,
            'flat_id' => $flatId,
            'valid_from' => $validFrom,
            ...$overrides,
        ]);
    }

    /**
     * The occupancy billed for the meter assignment, open-ended from the
     * given day with an opening reading of 0.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function meterOccupancyAssignment(int $meterAssignmentId, int $flatOccupancyId, string $validFrom, array $overrides = []): int
    {
        return (int) DB::table('meter_occupancy_assignments')->insertGetId([
            'meter_assignment_id' => $meterAssignmentId,
            'flat_occupancy_id' => $flatOccupancyId,
            'flat_id' => DB::table('flat_occupancies')->where('id', $flatOccupancyId)->value('flat_id'),
            'valid_from' => $validFrom,
            'opening_reading' => 0,
            ...$overrides,
        ]);
    }

    /**
     * A login of the given user (a new user when none is given). Row-level
     * security lets a session be written by its own user, so app.user_id is
     * set for this transaction.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function accessSession(?int $userId = null, array $overrides = []): int
    {
        $userId ??= User::factory()->create()->id;
        DB::select("select set_config('app.user_id', ?, true)", [(string) $userId]);

        return (int) DB::table('access_sessions')->insertGetId([
            'user_id' => $userId,
            'client_type' => 'browser',
            ...$overrides,
        ]);
    }
}
