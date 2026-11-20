<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One organization with a building and at least one row in every table under
 * row-level security, written in that organization's context (so the trail
 * rows are its own too). The row-level security suite builds two of them and
 * checks that each sees its own rows and none of the other's.
 *
 * Arranged as gasflow_test (BYPASSRLS), like every other test.
 */
final class TenantWorld
{
    private function __construct(
        public readonly int $organizationId,
        public readonly int $userId,
    ) {}

    /**
     * A new organization and user, and the transaction switched to their
     * context. Their own creation is logged in the context before, so it is
     * not part of the world.
     */
    public static function create(): self
    {
        $organizationId = TestRecords::organization();
        $userId = TestRecords::user();
        setDatabaseContext(['organization_id' => $organizationId, 'user_id' => $userId]);

        return new self($organizationId, $userId);
    }

    /**
     * Writes the world's rows, in its context.
     */
    public function fill(): void
    {
        $organizationId = $this->organizationId;
        $userId = $this->userId;
        setDatabaseContext(['organization_id' => $organizationId, 'user_id' => $userId]);

        // Organization, people and devices.
        $membershipId = TestRecords::membership($organizationId, $userId);
        DB::table('membership_roles')->insert(['membership_id' => $membershipId, 'role_id' => TestRecords::role('organization')]);
        TestRecords::userDevice($userId);
        TestRecords::accessSession($userId, ['organization_id' => $organizationId]);

        // Building, access and setup.
        $buildingId = TestRecords::building($organizationId);
        $buildingMembershipId = (int) DB::table('building_memberships')->insertGetId([
            'building_id' => $buildingId, 'organization_membership_id' => $membershipId,
        ]);
        DB::table('building_membership_roles')->insert(['building_membership_id' => $buildingMembershipId, 'role_id' => TestRecords::role('building')]);
        TestRecords::accessGrant($buildingId, TestRecords::organization());
        DB::table('building_ownerships')->insert(['building_id' => $buildingId, 'owner_name' => 'Karim', 'valid_from' => '2026-01-01']);
        TestRecords::setupTask($buildingId);
        TestRecords::pipelineZone($buildingId);
        TestRecords::attachment(['building_id' => $buildingId]);
        $subscriptionId = TestRecords::buildingSubscription(['building_id' => $buildingId]);
        $saasInvoiceId = TestRecords::saasInvoice(['building_subscription_id' => $subscriptionId]);
        DB::table('saas_payments')->insert(['saas_invoice_id' => $saasInvoiceId, 'amount' => 1500, 'method' => 'bkash']);
        DB::table('portal_access_policies')->insert(['organization_id' => $organizationId]);

        // Flats, customers and meters.
        $flatId = TestRecords::flat($buildingId);
        $occupancyId = TestRecords::occupancy($flatId, '2026-01-01');
        $customerId = (int) DB::table('flat_occupancies')->where('id', $occupancyId)->value('customer_id');
        $meterId = TestRecords::meter($buildingId);
        $mainMeterId = TestRecords::meter($buildingId, ['meter_type' => 'main']);
        $meterAssignmentId = TestRecords::meterAssignment($meterId, $flatId, '2026-01-01');
        TestRecords::meterOccupancyAssignment($meterAssignmentId, $occupancyId, '2026-01-01');
        DB::table('meter_events')->insert(['meter_id' => $meterId, 'event_type' => 'installed', 'event_at' => now(), 'reading_at_event' => 0]);

        // Readings.
        $periodId = TestRecords::readingPeriod($buildingId, 9);
        $readingId = TestRecords::meterReading($periodId, $meterId);
        DB::table('meter_reading_revisions')->insert([
            'meter_reading_id' => $readingId, 'revision_no' => 1, 'previous_reading' => 0, 'current_reading' => 12,
            'consumption' => 12, 'reason' => 'Photo shows 12, not 10', 'submitted_by' => $userId,
        ]);
        DB::table('reading_approvals')->insert(['meter_reading_id' => $readingId, 'reviewer_user_id' => $userId, 'decision' => 'approved']);
        DB::table('meter_reconciliations')->insert([
            'building_id' => $buildingId, 'reading_period_id' => $periodId, 'main_meter_id' => $mainMeterId,
            'main_consumption' => 100, 'submeter_total' => 96, 'variance' => 4,
        ]);

        // Gas supply and tariffs.
        $assignmentId = TestRecords::vendorAssignment($buildingId, '2026-01-01');
        $tariffId = TestRecords::approvedTariffVersion($assignmentId);
        TestRecords::chargeRule($buildingId);
        $supplyChangeId = TestRecords::supplyChange($tariffId, '2026-06-01');
        TestRecords::supplyChangeReading($supplyChangeId, $meterId, 5);
        TestRecords::gasSupplyReceipt($assignmentId);

        // Billing.
        $cycleId = TestRecords::billingCycle(TestRecords::approvedReadingPeriod($buildingId, 10));
        $invoiceId = TestRecords::issuedInvoice($cycleId);
        $lineId = (int) DB::table('invoice_lines')->where('invoice_id', $invoiceId)->value('id');
        TestRecords::creditNote($invoiceId);
        TestRecords::billingDispute($invoiceId);
        TestRecords::retroAdjustment($lineId, $tariffId);
        TestRecords::guestLink($invoiceId);
        TestRecords::paymentGateway(['organization_id' => $organizationId]);
        TestRecords::smsGateway(['organization_id' => $organizationId]);
        $paymentId = TestRecords::receivedPayment($invoiceId);
        TestRecords::paymentAllocation($invoiceId, $paymentId);
        TestRecords::paymentAttempt(TestRecords::payment($buildingId));
        TestRecords::refund(TestRecords::payment($buildingId, ['status' => 'succeeded']));
        TestRecords::installment(TestRecords::installationAgreement($buildingId));

        // Ledger.
        TestRecords::postedEntry($organizationId);

        // Notifications, audit and integrations.
        DB::table('notification_templates')->insert([
            'organization_id' => $organizationId, 'event_code' => 'invoice.issued', 'channel' => 'sms', 'locale' => 'bn',
            'body' => 'আপনার {month} মাসের গ্যাস বিল ৳{amount}',
        ]);
        DB::table('notifications')->insert([
            'building_id' => $buildingId, 'customer_id' => $customerId,
            'channel' => 'sms', 'event_code' => 'invoice.issued', 'message' => 'Your bill is ready',
        ]);
        DB::table('audit_logs')->insert([
            'organization_id' => $organizationId, 'action' => 'invoice.issued', 'subject_type' => 'invoice', 'subject_id' => $invoiceId,
        ]);
        DB::table('idempotency_keys')->insert([
            'organization_id' => $organizationId, 'key' => (string) Str::uuid(),
            'operation' => 'payments.create', 'request_hash' => str_repeat('a', 64),
        ]);
        $outboxId = (int) DB::table('integration_outbox')->insertGetId([
            'organization_id' => $organizationId, 'event_uuid' => (string) Str::uuid(),
            'aggregate_type' => 'payment', 'aggregate_id' => $paymentId, 'event_type' => 'payment.received', 'payload' => '{}',
        ]);
        DB::table('integration_delivery_attempts')->insert(['outbox_id' => $outboxId, 'provider_code' => 'sms_generic', 'status' => 'failed']);
        DB::table('webhook_inbox')->insert([
            'organization_id' => $organizationId, 'provider_code' => 'bkash',
            'provider_event_id' => (string) Str::uuid(), 'event_type' => 'payment.completed', 'payload' => '{"amount":"500.00"}',
        ]);
    }
}
