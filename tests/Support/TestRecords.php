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

    /**
     * A new user's id.
     */
    public static function user(): int
    {
        return User::factory()->create()->id;
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
     * The occupancy of the flat by a new customer of the building's tenant
     * organization, open-ended from the given day.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function occupancy(int $flatId, string $validFrom, array $overrides = []): int
    {
        $tenantId = DB::table('flats')->join('buildings', 'buildings.id', '=', 'flats.building_id')
            ->where('flats.id', $flatId)->value('buildings.tenant_organization_id');

        return (int) DB::table('flat_occupancies')->insertGetId([
            'flat_id' => $flatId,
            'customer_id' => self::customer($tenantId),
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
     * The meter placed in the flat of its building (no flat: a main or
     * common-area meter), open-ended from the given day.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function meterAssignment(int $meterId, ?int $flatId, string $validFrom, array $overrides = []): int
    {
        return (int) DB::table('meter_assignments')->insertGetId([
            'meter_id' => $meterId,
            'building_id' => DB::table('meters')->where('id', $meterId)->value('building_id'),
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
     * The given month of 2026 as an open reading period of the building (a
     * new building when none is given).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function readingPeriod(?int $buildingId = null, int $month = 10, array $overrides = []): int
    {
        $start = now()->setDate(2026, $month, 1);

        return (int) DB::table('reading_periods')->insertGetId([
            'building_id' => $buildingId ?? self::building(),
            'period_year' => 2026,
            'period_month' => $month,
            'starts_on' => $start->toDateString(),
            'ends_on' => $start->endOfMonth()->toDateString(),
            ...$overrides,
        ]);
    }

    /**
     * A submitted reading in the period of a meter of the period's building (a
     * new meter when none is given): 0 → 10 at factor 1, taken now by a new
     * user, with a fresh idempotency key.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function meterReading(int $readingPeriodId, ?int $meterId = null, array $overrides = []): int
    {
        $buildingId = DB::table('reading_periods')->where('id', $readingPeriodId)->value('building_id');

        return (int) DB::table('meter_readings')->insertGetId([
            'reading_period_id' => $readingPeriodId,
            'building_id' => $buildingId,
            'meter_id' => $meterId ?? self::meter($buildingId),
            'previous_reading' => 0,
            'current_reading' => 10,
            'meter_factor_snapshot' => 1,
            'consumption' => 10,
            'reading_taken_at' => now(),
            'submitted_by' => self::user(),
            'idempotency_key' => (string) Str::uuid7(),
            ...$overrides,
        ]);
    }

    /**
     * A draft tariff version of the vendor assignment's vendor and building (a
     * new building with a gas vendor from 2026-01-01 when none is given): the
     * next version of tariff DOMESTIC at 30 BDT per m3 from the given day.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function tariffVersion(?int $vendorAssignmentId = null, string $effectiveFrom = '2026-01-01', array $overrides = []): int
    {
        $vendorAssignmentId ??= self::vendorAssignment(self::building(), '2026-01-01');
        $assignment = DB::table('building_vendor_assignments')->where('id', $vendorAssignmentId)->first(['building_id', 'vendor_organization_id']);
        $row = [
            'building_id' => $assignment->building_id,
            'vendor_organization_id' => $assignment->vendor_organization_id,
            'building_vendor_assignment_id' => $vendorAssignmentId,
            'tariff_code' => 'DOMESTIC',
            'name' => 'Domestic gas',
            'unit' => 'm3',
            'rate' => 30,
            'effective_from' => $effectiveFrom,
            ...$overrides,
        ];
        $row['version_no'] ??= (int) DB::table('tariff_versions')
            ->where('building_id', $row['building_id'])
            ->where('vendor_organization_id', $row['vendor_organization_id'])
            ->where('tariff_code', $row['tariff_code'])
            ->max('version_no') + 1;

        return (int) DB::table('tariff_versions')->insertGetId($row);
    }

    /**
     * The approved general tariff version of the vendor assignment for the
     * tariff code, from the assignment's start (created and approved on first
     * use).
     */
    public static function approvedTariffVersion(int $vendorAssignmentId, string $tariffCode = 'DOMESTIC'): int
    {
        $id = DB::table('tariff_versions')->where('building_vendor_assignment_id', $vendorAssignmentId)
            ->where('tariff_code', $tariffCode)->whereNull('customer_category')->whereIn('status', ['approved', 'active'])->value('id');
        if ($id !== null) {
            return (int) $id;
        }
        $id = self::tariffVersion($vendorAssignmentId, (string) DB::table('building_vendor_assignments')->where('id', $vendorAssignmentId)->value('valid_from'),
            ['tariff_code' => $tariffCode]);
        DB::table('tariff_versions')->where('id', $id)->update(['status' => 'approved']);

        return $id;
    }

    /**
     * An active fixed service charge of 100 BDT per invoice in the building (a
     * new building when none is given) from the given day.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function chargeRule(?int $buildingId = null, string $effectiveFrom = '2026-01-01', array $overrides = []): int
    {
        return (int) DB::table('building_charge_rules')->insertGetId([
            'building_id' => $buildingId ?? self::building(),
            'charge_code' => 'SERVICE',
            'name' => 'Service charge',
            'calculation_type' => 'fixed_per_invoice',
            'amount' => 100,
            'effective_from' => $effectiveFrom,
            ...$overrides,
        ]);
    }

    /**
     * A recorded gas supply change of the tariff version's building, vendor
     * and price, taking effect on the given day (changed at 10:00 that day
     * in Dhaka).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function supplyChange(int $tariffVersionId, string $effectiveOn, array $overrides = []): int
    {
        $tariff = DB::table('tariff_versions')->where('id', $tariffVersionId)
            ->first(['building_id', 'vendor_organization_id', 'building_vendor_assignment_id']);

        return (int) DB::table('supply_changes')->insertGetId([
            'building_id' => $tariff->building_id,
            'vendor_organization_id' => $tariff->vendor_organization_id,
            'building_vendor_assignment_id' => $tariff->building_vendor_assignment_id,
            'tariff_version_id' => $tariffVersionId,
            'changed_at' => "{$effectiveOn} 10:00:00+06",
            'effective_on' => $effectiveOn,
            'reason' => 'vendor_changed',
            ...$overrides,
        ]);
    }

    /**
     * A meter reading taken at the supply change.
     */
    public static function supplyChangeReading(int $supplyChangeId, int $meterId, float|string $reading): int
    {
        return (int) DB::table('supply_change_readings')->insertGetId([
            'supply_change_id' => $supplyChangeId,
            'building_id' => DB::table('supply_changes')->where('id', $supplyChangeId)->value('building_id'),
            'meter_id' => $meterId,
            'reading' => $reading,
        ]);
    }

    /**
     * The given month of 2026 as an approved reading period of the building
     * (a new building when none is given), ready to be billed.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function approvedReadingPeriod(?int $buildingId = null, int $month = 10, array $overrides = []): int
    {
        $id = self::readingPeriod($buildingId, $month, $overrides);
        foreach (['collecting', 'review', 'approved'] as $status) {
            DB::table('reading_periods')->where('id', $id)->update(['status' => $status]);
        }

        return $id;
    }

    /**
     * A billing setting of the building (a new building when none is given)
     * for authorized meter billing: monthly from the 1st, from the given day.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function billingSetting(?int $buildingId = null, string $effectiveFrom = '2026-01-01', array $overrides = []): int
    {
        return (int) DB::table('building_billing_settings')->insertGetId([
            'building_id' => $buildingId ?? self::building(),
            'billing_mode' => 'authorized_meter_billing',
            'effective_from' => $effectiveFrom,
            'frequency' => 'monthly',
            ...$overrides,
        ]);
    }

    /**
     * The draft billing cycle of the reading period's building and month (a
     * new approved October 2026 period of a new building when none is given),
     * due 15 days after the month, under the stream's billing setting of the
     * period's first day (a new monthly one from 2026-01-01 when none).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function billingCycle(?int $readingPeriodId = null, array $overrides = []): int
    {
        $period = DB::table('reading_periods')->where('id', $readingPeriodId ?? self::approvedReadingPeriod())->first();
        $mode = $overrides['billing_mode'] ?? 'authorized_meter_billing';
        $settingId = $overrides['billing_setting_id'] ?? DB::selectOne('select (building_billing_setting_on(?, ?, ?)).id', [$period->building_id, $mode, $period->starts_on])->id
            ?? self::billingSetting($period->building_id, '2026-01-01', ['billing_mode' => $mode]);

        return (int) DB::table('billing_cycles')->insertGetId([
            'building_id' => $period->building_id,
            'reading_period_id' => $period->id,
            'billing_setting_id' => $settingId,
            'period_year' => $period->period_year,
            'period_month' => $period->period_month,
            'billing_mode' => $mode,
            'starts_on' => $period->starts_on,
            'ends_on' => $period->ends_on,
            'due_on' => now()->parse($period->ends_on)->addDays(15)->toDateString(),
            ...$overrides,
        ]);
    }

    /**
     * A draft invoice without lines (amounts 0) in the billing cycle (a new cycle when none
     * is given) for a new flat of its building and a new customer of the
     * building's tenant (the occupant when a billed occupancy is given),
     * billed for the building's first gas vendor assignment (a new one from
     * 2026-01-01 when the building has none).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function invoice(?int $billingCycleId = null, array $overrides = []): int
    {
        $cycle = DB::table('billing_cycles')->where('id', $billingCycleId ?? self::billingCycle())->first();
        $tenantId = DB::table('buildings')->where('id', $cycle->building_id)->value('tenant_organization_id');
        $assignmentId = DB::table('building_vendor_assignments')->where('building_id', $cycle->building_id)->min('id')
            ?? self::vendorAssignment($cycle->building_id, '2026-01-01');

        return (int) DB::table('invoices')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()),
            'building_id' => $cycle->building_id,
            'billing_cycle_id' => $cycle->id,
            'flat_id' => self::flat($cycle->building_id),
            'customer_id' => isset($overrides['meter_occupancy_assignment_id'])
                ? DB::table('meter_occupancy_assignments')->join('flat_occupancies', 'flat_occupancies.id', '=', 'meter_occupancy_assignments.flat_occupancy_id')
                    ->where('meter_occupancy_assignments.id', $overrides['meter_occupancy_assignment_id'])->value('flat_occupancies.customer_id')
                : self::customer($tenantId),
            'gas_vendor_organization_id' => DB::table('building_vendor_assignments')->where('id', $assignmentId)->value('vendor_organization_id'),
            'gas_vendor_name_snapshot' => 'Northstar Gas Services',
            'building_vendor_assignment_id' => $assignmentId,
            'invoice_no' => 'INV-'.Str::upper(Str::random(10)),
            'billing_mode' => $cycle->billing_mode,
            'customer_name_snapshot' => 'Rahima Begum',
            'flat_code_snapshot' => 'A-1',
            'unit_snapshot' => 'm3',
            'consumption' => 100,
            ...$overrides,
        ]);
    }

    /**
     * A gas line of the invoice for 1–10 October 2026: 100 m3 at 30 BDT,
     * split by the daily average, priced with the given tariff version (the
     * approved DOMESTIC rate of the invoice's vendor assignment when none is
     * given).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function gasChargeLine(int $invoiceId, ?int $tariffVersionId = null, array $overrides = []): int
    {
        return (int) DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $invoiceId,
            'line_type' => 'gas_charge',
            'description' => 'Gas 1–10 Oct at 30.00',
            'quantity' => 100,
            'unit' => 'm3',
            'unit_rate' => 30,
            'line_total' => 3000,
            'tariff_version_id' => $tariffVersionId ?? self::approvedTariffVersion(
                (int) DB::table('invoices')->where('id', $invoiceId)->value('building_vendor_assignment_id'),
            ),
            'segment_from' => '2026-10-01',
            'segment_to' => '2026-10-10',
            'split_method' => 'days',
            ...$overrides,
        ]);
    }

    /**
     * Moves a draft invoice along the given statuses, first filling its
     * amounts from its lines with invoice_line_totals(), as the billing
     * action does.
     *
     * @param  list<string>  $statuses
     */
    public static function moveInvoice(int $invoiceId, array $statuses): void
    {
        $totals = (array) DB::selectOne('select * from invoice_line_totals(?)', [$invoiceId]);
        DB::table('invoices')->where('id', $invoiceId)->where('status', 'draft')->update($totals);
        foreach ($statuses as $status) {
            DB::table('invoices')->where('id', $invoiceId)->update(['status' => $status]);
        }
    }

    /**
     * An issued invoice of 3,000 BDT with one gas line, in the billing cycle
     * (a new cycle when none is given).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function issuedInvoice(?int $billingCycleId = null, array $overrides = []): int
    {
        $id = self::invoice($billingCycleId, $overrides);
        self::gasChargeLine($id);
        self::moveInvoice($id, ['approved', 'issued']);

        return $id;
    }

    /**
     * A credit note of 200 BDT on the invoice (a new issued invoice of 3,000
     * BDT when none is given), raised by a new user and waiting for approval.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function creditNote(?int $invoiceId = null, array $overrides = []): int
    {
        return (int) DB::table('credit_notes')->insertGetId([
            'invoice_id' => $invoiceId ?? self::issuedInvoice(),
            'credit_note_no' => 'CN-'.Str::upper(Str::random(10)),
            'amount' => 200,
            'reason' => 'Meter read twice',
            'created_by' => self::user(),
            ...$overrides,
        ]);
    }

    /**
     * An open dispute of the invoice's customer about the invoice (a new
     * draft invoice when none is given).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function billingDispute(?int $invoiceId = null, array $overrides = []): int
    {
        $invoice = DB::table('invoices')->where('id', $invoiceId ?? self::invoice())->first(['id', 'building_id', 'customer_id']);

        return (int) DB::table('billing_disputes')->insertGetId([
            'building_id' => $invoice->building_id,
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'dispute_no' => 'DSP-'.Str::upper(Str::random(10)),
            'category' => 'reading',
            'description' => 'The reading on the bill is higher than my meter shows.',
            ...$overrides,
        ]);
    }

    /**
     * The pending retro difference of the gas line under the tariff version
     * of its building: the line's days and quantity, billed 3,000 BDT,
     * recalculated 3,300 BDT.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function retroAdjustment(int $originalLineId, int $tariffVersionId, array $overrides = []): int
    {
        $line = DB::table('invoice_lines')->where('id', $originalLineId)->first(['segment_from', 'segment_to', 'quantity']);

        return (int) DB::table('tariff_retro_adjustments')->insertGetId([
            'building_id' => DB::table('tariff_versions')->where('id', $tariffVersionId)->value('building_id'),
            'tariff_version_id' => $tariffVersionId,
            'original_invoice_line_id' => $originalLineId,
            'segment_from' => $line->segment_from,
            'segment_to' => $line->segment_to,
            'quantity' => $line->quantity,
            'billed_amount' => 3000,
            'recalculated_amount' => 3300,
            'difference' => 300,
            ...$overrides,
        ]);
    }

    /**
     * A cash payment of 3,000 BDT by a new customer of the building's tenant
     * (a new building when none is given), as initiated, collected by the
     * building's owner organization for its internal cost allocation. A
     * payment written as already received gets its receive time (now).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function payment(?int $buildingId = null, array $overrides = []): int
    {
        $buildingId ??= self::building();
        $ownerId = (int) DB::table('buildings')->where('id', $buildingId)->value('tenant_organization_id');
        if (in_array($overrides['status'] ?? null, ['succeeded', 'reversed', 'refunded'], true)) {
            $overrides['received_at'] ??= now();
        }

        return (int) DB::table('payments')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()),
            'building_id' => $buildingId,
            'customer_id' => $overrides['customer_id'] ?? self::customer($ownerId),
            'payment_no' => 'PAY-'.Str::upper(Str::random(10)),
            'amount' => 3000,
            'method' => 'cash',
            'billing_mode' => 'internal_cost_allocation',
            'payee_organization_id' => $ownerId,
            ...$overrides,
        ]);
    }

    /**
     * An initiated bKash attempt for the whole payment (a new payment when
     * none is given), with a new idempotency key.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function paymentAttempt(?int $paymentId = null, array $overrides = []): int
    {
        $payment = DB::table('payments')->where('id', $paymentId ?? self::payment())->first(['id', 'amount', 'currency']);

        return (int) DB::table('payment_attempts')->insertGetId([
            'payment_id' => $payment->id,
            'provider' => 'bkash',
            'idempotency_key' => (string) Str::uuid(),
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            ...$overrides,
        ]);
    }

    /**
     * A succeeded cash payment of 3,000 BDT by the invoice's customer in the
     * invoice's building, received now by whoever collects the invoice: its
     * gas vendor for authorized billing, the building's owner for internal
     * cost allocation.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function receivedPayment(int $invoiceId, array $overrides = []): int
    {
        $invoice = DB::table('invoices')->join('buildings', 'buildings.id', '=', 'invoices.building_id')
            ->where('invoices.id', $invoiceId)
            ->first(['invoices.building_id', 'invoices.customer_id', 'invoices.billing_mode', 'invoices.gas_vendor_organization_id', 'buildings.tenant_organization_id']);

        return self::payment($invoice->building_id, [
            'customer_id' => $invoice->customer_id,
            'billing_mode' => $invoice->billing_mode,
            'payee_organization_id' => $invoice->billing_mode === 'authorized_meter_billing'
                ? $invoice->gas_vendor_organization_id
                : $invoice->tenant_organization_id,
            'status' => 'succeeded',
            'received_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * 1,000 BDT of the payment (a new received payment of the invoice's
     * customer when none is given) settling the invoice (a new issued
     * invoice of 3,000 BDT when none is given), for the invoice's customer
     * and billing mode.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function paymentAllocation(?int $invoiceId = null, ?int $paymentId = null, array $overrides = []): int
    {
        $invoice = DB::table('invoices')->where('id', $invoiceId ?? self::issuedInvoice())->first(['id', 'building_id', 'customer_id', 'billing_mode']);

        return (int) DB::table('payment_allocations')->insertGetId([
            'payment_id' => $paymentId ?? self::receivedPayment($invoice->id),
            'invoice_id' => $invoice->id,
            'building_id' => $invoice->building_id,
            'customer_id' => $invoice->customer_id,
            'billing_mode' => $invoice->billing_mode,
            'amount' => 1000,
            ...$overrides,
        ]);
    }

    /**
     * Settles the issued invoice as the payment action does: a received
     * payment of the given amount (the whole due when none is given) is
     * allocated to it, and the invoice moves to paid or partially paid in
     * the same transaction. Returns the allocation.
     */
    public static function payInvoice(int $invoiceId, float|string|null $amount = null): int
    {
        $amount ??= DB::selectOne('select due from invoice_balance(?)', [$invoiceId])->due;
        $allocationId = self::paymentAllocation($invoiceId, self::receivedPayment($invoiceId, ['amount' => $amount]), ['amount' => $amount]);
        $due = (float) DB::selectOne('select due from invoice_balance(?)', [$invoiceId])->due;
        DB::table('invoices')->where('id', $invoiceId)->update(['status' => $due > 0 ? 'partially_paid' : 'paid']);

        return $allocationId;
    }

    /**
     * A requested refund of 500 BDT from the payment (a new succeeded
     * payment of 3,000 BDT when none is given), raised by a new user.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function refund(?int $paymentId = null, array $overrides = []): int
    {
        return (int) DB::table('refunds')->insertGetId([
            'payment_id' => $paymentId ?? self::payment(overrides: ['status' => 'succeeded', 'received_at' => now()]),
            'refund_no' => 'RF-'.Str::upper(Str::random(10)),
            'amount' => 500,
            'reason' => 'Paid twice',
            'created_by' => self::user(),
            ...$overrides,
        ]);
    }

    /**
     * A refund of the payment's unallocated money (or the given amount),
     * approved, processed and completed. Returns the refund.
     */
    public static function completeRefund(int $paymentId, float|string|null $amount = null): int
    {
        $amount ??= DB::selectOne('select unallocated from payment_balance(?)', [$paymentId])->unallocated;
        $id = self::refund($paymentId, ['amount' => $amount]);
        DB::table('refunds')->where('id', $id)->update(['status' => 'approved']);
        DB::table('refunds')->where('id', $id)->update(['status' => 'processing']);
        DB::table('refunds')->where('id', $id)->update(['status' => 'completed', 'processed_at' => now()]);

        return $id;
    }

    /**
     * An asset account (BDT) of the organization (a new one when none is given).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function financialAccount(?int $organizationId = null, array $overrides = []): int
    {
        return (int) DB::table('financial_accounts')->insertGetId([
            'organization_id' => $organizationId ?? self::organization(),
            'code' => 'A-'.Str::upper(Str::random(6)),
            'name' => 'Accounts receivable',
            'account_type' => 'asset',
            ...$overrides,
        ]);
    }

    /**
     * A draft journal (BDT, today) of the organization (a new one when none
     * is given) for a new invoice source.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function journal(?int $organizationId = null, array $overrides = []): int
    {
        return (int) DB::table('financial_journals')->insertGetId([
            'organization_id' => $organizationId ?? self::organization(),
            'journal_no' => 'J-'.Str::upper(Str::random(10)),
            'journal_date' => today()->toDateString(),
            'source_type' => 'invoice',
            'source_id' => random_int(1, PHP_INT_MAX),
            ...$overrides,
        ]);
    }

    /**
     * One side of a journal: a debit (positive amount) or credit (negative
     * amount) on the account, in the journal's organization.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function journalLine(int $journalId, int $accountId, float|int $amount, array $overrides = []): int
    {
        return (int) DB::table('financial_journal_lines')->insertGetId([
            'journal_id' => $journalId,
            'account_id' => $accountId,
            'organization_id' => DB::table('financial_journals')->where('id', $journalId)->value('organization_id'),
            'debit' => max($amount, 0),
            'credit' => max(-$amount, 0),
            ...$overrides,
        ]);
    }

    /**
     * A draft journal of the organization with two balanced lines: the
     * amount debited to a receivable and credited to a revenue account.
     */
    public static function balancedJournal(?int $organizationId = null, float|int $amount = 100): int
    {
        $organizationId ??= self::organization();
        $id = self::journal($organizationId);
        self::journalLine($id, self::financialAccount($organizationId), $amount);
        self::journalLine($id, self::financialAccount($organizationId, ['name' => 'Gas revenue', 'account_type' => 'revenue']), -$amount);

        return $id;
    }

    /**
     * The financial transaction of the posted journal: its organization,
     * source, currency and debit total, as a SaaS subscription payment (a
     * type that names no invoice or payment).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function financialTransaction(int $journalId, array $overrides = []): int
    {
        $journal = DB::table('financial_journals')->where('id', $journalId)->first();

        return (int) DB::table('financial_transactions')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()),
            'transaction_no' => 'FT-'.Str::upper(Str::random(10)),
            'organization_id' => $journal->organization_id,
            'building_id' => $journal->building_id,
            'journal_id' => $journal->id,
            'transaction_type' => 'saas_subscription_paid',
            'direction' => 'debit',
            'amount' => DB::table('financial_journal_lines')->where('journal_id', $journalId)->sum('debit'),
            'currency' => $journal->currency,
            'source_type' => $journal->source_type,
            'source_id' => $journal->source_id,
            ...$overrides,
        ]);
    }

    /**
     * A balanced journal of the organization, posted with its financial
     * transaction, as the posting service does. Returns the journal.
     */
    public static function postedEntry(?int $organizationId = null, float|int $amount = 100): int
    {
        $id = self::balancedJournal($organizationId, $amount);
        DB::table('financial_journals')->where('id', $id)->update(['status' => 'posted', 'posted_at' => now()]);
        self::financialTransaction($id);

        return $id;
    }

    /**
     * A draft reversal journal of the journal: source journal_reversal, the
     * original's lines with their sides swapped (or the given amount on two
     * new accounts when one is given). Returns the reversal journal.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function reversalJournal(int $originalId, ?int $amount = null, array $overrides = []): int
    {
        $original = DB::table('financial_journals')->where('id', $originalId)->first();
        $id = self::journal((int) $original->organization_id, [
            'source_type' => 'journal_reversal', 'source_id' => $originalId, 'reversal_of_journal_id' => $originalId, ...$overrides,
        ]);
        if ($amount !== null) {
            self::journalLine($id, self::financialAccount((int) $original->organization_id), $amount);
            self::journalLine($id, self::financialAccount((int) $original->organization_id), -$amount);

            return $id;
        }
        foreach (DB::table('financial_journal_lines')->where('journal_id', $originalId)->get() as $line) {
            self::journalLine($id, (int) $line->account_id, (float) $line->credit - (float) $line->debit);
        }

        return $id;
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

    /**
     * A draft installation agreement of the building (a new building when
     * none is given): 12 000 BDT in 12 parts from 2026-01-01, with a fresh
     * agreement number.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function installationAgreement(?int $buildingId = null, array $overrides = []): int
    {
        return (int) DB::table('installation_agreements')->insertGetId([
            'building_id' => $buildingId ?? self::building(),
            'agreement_no' => 'IA-'.Str::random(10),
            'description' => 'Gas line installation',
            'principal_amount' => 12000,
            'installment_count' => 12,
            'start_date' => '2026-01-01',
            ...$overrides,
        ]);
    }

    /**
     * Installment part number $no of the agreement (a new agreement when none
     * is given): 1 000 BDT due on the first of month $no of 2026.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function installment(?int $agreementId = null, int $no = 1, array $overrides = []): int
    {
        $agreementId ??= self::installationAgreement();

        return (int) DB::table('installation_installments')->insertGetId([
            'installation_agreement_id' => $agreementId,
            'building_id' => DB::table('installation_agreements')->where('id', $agreementId)->value('building_id'),
            'installment_no' => $no,
            'due_date' => sprintf('2026-%02d-01', min($no, 12)),
            'amount' => 1000,
            ...$overrides,
        ]);
    }

    /**
     * A guest link to the invoice (a new issued invoice when none is given):
     * one use, valid for a week, with a fresh token hash.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function guestLink(?int $invoiceId = null, array $overrides = []): int
    {
        return (int) DB::table('guest_access_links')->insertGetId([
            'invoice_id' => $invoiceId ?? self::issuedInvoice(),
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addWeek(),
            ...$overrides,
        ]);
    }

    /**
     * An inactive bKash sandbox payment gateway of a new organization (unless
     * the overrides name the scope).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function paymentGateway(array $overrides = []): int
    {
        $scope = array_key_exists('organization_id', $overrides) || array_key_exists('building_id', $overrides)
            ? [] : ['organization_id' => self::organization()];

        return (int) DB::table('organization_payment_gateways')->insertGetId([
            ...$scope,
            'provider' => 'bkash',
            'credentials_encrypted' => 'eyJpdiI6ImNyZWRlbnRpYWxzIn0=',
            ...$overrides,
        ]);
    }

    /**
     * An inactive generic SMS gateway of a new organization (unless the
     * overrides name the scope).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function smsGateway(array $overrides = []): int
    {
        $scope = array_key_exists('organization_id', $overrides) || array_key_exists('building_id', $overrides)
            ? [] : ['organization_id' => self::organization()];

        return (int) DB::table('organization_sms_gateways')->insertGetId([
            ...$scope,
            'provider' => 'sms_generic',
            'credentials_encrypted' => 'eyJpdiI6ImNyZWRlbnRpYWxzIn0=',
            ...$overrides,
        ]);
    }

    /**
     * A pipeline zone of the building (a new building when none is given)
     * with a fresh code.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function pipelineZone(?int $buildingId = null, array $overrides = []): int
    {
        return (int) DB::table('pipeline_zones')->insertGetId([
            'building_id' => $buildingId ?? self::building(),
            'code' => 'Z-'.Str::random(6),
            'name' => 'Riser zone',
            ...$overrides,
        ]);
    }

    /**
     * A pending pipeline setup task of the building (a new building when none
     * is given).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function setupTask(?int $buildingId = null, array $overrides = []): int
    {
        return (int) DB::table('building_setup_tasks')->insertGetId([
            'building_id' => $buildingId ?? self::building(),
            'setup_area_code' => 'pipeline',
            'title' => 'Lay the riser',
            ...$overrides,
        ]);
    }

    /**
     * A draft receipt of 1 000 m3 at 40 BDT bought under the vendor assignment
     * (a new one from 2026-01-01 of a new building when none is given), with
     * a fresh supply number.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function gasSupplyReceipt(?int $assignmentId = null, array $overrides = []): int
    {
        $assignment = DB::table('building_vendor_assignments')
            ->where('id', $assignmentId ?? self::vendorAssignment(self::building(), '2026-01-01'))
            ->first(['id', 'building_id', 'vendor_organization_id']);

        return (int) DB::table('gas_supply_receipts')->insertGetId([
            'building_id' => $assignment->building_id,
            'vendor_organization_id' => $assignment->vendor_organization_id,
            'building_vendor_assignment_id' => $assignment->id,
            'supply_no' => 'GS-'.Str::random(8),
            'upstream_supplier_name' => 'Titas Gas',
            'supply_date' => '2026-10-05',
            'quantity' => 1000,
            'unit_cost' => 40,
            'total_cost' => 40000,
            ...$overrides,
        ]);
    }

    /**
     * A draft trial license for one installation, valid through 2026, with a
     * fresh public id and license number.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function license(array $overrides = []): int
    {
        return (int) DB::table('licenses')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'license_no' => 'LIC-'.Str::random(8),
            'licensee_name' => 'Test licensee',
            'edition' => 'trial',
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-12-31',
            ...$overrides,
        ]);
    }

    /**
     * A trial license signed now with a new active signing key and moved to
     * issued.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function issuedLicense(array $overrides = []): int
    {
        $keyId = (int) DB::table('license_signing_keys')->insertGetId([
            'key_id' => 'k-'.Str::random(8),
            'public_key' => 'MCowBQYDK2VwAyEA'.Str::random(32),
            'valid_from' => now()->subDay(),
        ]);
        $id = self::license([
            'signing_key_id' => $keyId,
            'signed_payload' => '{"edition":"trial"}',
            'signature' => 'c2lnbmF0dXJl',
            'issued_at' => now(),
            ...$overrides,
        ]);
        DB::table('licenses')->where('id', $id)->update(['status' => 'issued']);

        return $id;
    }

    /**
     * An activation of the license (a new issued license when none is given)
     * on a new installation.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function licenseActivation(?int $licenseId = null, array $overrides = []): int
    {
        return (int) DB::table('license_activations')->insertGetId([
            'license_id' => $licenseId ?? self::issuedLicense(),
            'installation_id' => (string) Str::uuid(),
            'domain' => 'gas.example.test',
            'fingerprint_hash' => hash('sha256', Str::random(20)),
            ...$overrides,
        ]);
    }
}
