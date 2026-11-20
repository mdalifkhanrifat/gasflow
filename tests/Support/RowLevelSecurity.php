<?php

namespace Tests\Support;

/**
 * The tables under row-level security and their one policy each: the
 * design's 55 (02 §15, final form in 02b) and the 8 decision D52 added.
 */
final class RowLevelSecurity
{
    /**
     * Design 02b: table => policy.
     *
     * @var array<string, string>
     */
    public const array DESIGN_POLICIES = [
        'access_sessions' => 'access_sessions_scope',
        'attachments' => 'attachments_scope',
        'audit_logs' => 'audit_logs_org_scope',
        'billing_cycles' => 'tenant_scope_billing_cycles',
        'building_access_grants' => 'access_grants_scope',
        'building_charge_rules' => 'tenant_scope_building_charge_rules',
        'building_memberships' => 'building_memberships_scope',
        'building_ownerships' => 'building_ownerships_scope',
        'building_setup_tasks' => 'tenant_scope_building_setup_tasks',
        'building_subscriptions' => 'tenant_scope_building_subscriptions',
        'buildings' => 'buildings_tenant_scope',
        'credit_notes' => 'credit_notes_scope',
        'customers' => 'customers_scope',
        'data_change_logs' => 'data_change_logs_scope',
        'financial_accounts' => 'financial_accounts_org_scope',
        'financial_journal_lines' => 'financial_journal_lines_org_scope',
        'financial_journals' => 'financial_journals_org_scope',
        'financial_transactions' => 'financial_transactions_org_scope',
        'flat_occupancies' => 'flat_occupancies_scope',
        'flats' => 'tenant_scope_flats',
        'gas_supply_receipts' => 'tenant_scope_gas_supply_receipts',
        'guest_access_links' => 'guest_access_links_scope',
        'idempotency_keys' => 'idempotency_keys_org_scope',
        'installation_agreements' => 'tenant_scope_installation_agreements',
        'installation_installments' => 'installation_installments_scope',
        'integration_delivery_attempts' => 'integration_delivery_attempts_scope',
        'integration_outbox' => 'integration_outbox_org_scope',
        'invoice_lines' => 'invoice_lines_scope',
        'invoices' => 'tenant_scope_invoices',
        'meter_assignments' => 'meter_assignments_scope',
        'meter_events' => 'meter_events_scope',
        'meter_occupancy_assignments' => 'meter_occupancy_assignments_scope',
        'meter_reading_revisions' => 'meter_reading_revisions_scope',
        'meter_readings' => 'meter_readings_scope',
        'meter_reconciliations' => 'tenant_scope_meter_reconciliations',
        'meters' => 'tenant_scope_meters',
        'notification_templates' => 'notification_templates_scope',
        'notifications' => 'notifications_scope',
        'organization_payment_gateways' => 'payment_gateway_config_scope',
        'organization_sms_gateways' => 'sms_gateway_config_scope',
        'payment_allocations' => 'payment_allocations_scope',
        'payment_attempts' => 'payment_attempts_scope',
        'payments' => 'tenant_scope_payments',
        'pipeline_zones' => 'tenant_scope_pipeline_zones',
        'portal_access_policies' => 'portal_access_policy_scope',
        'reading_approvals' => 'reading_approvals_scope',
        'reading_periods' => 'tenant_scope_reading_periods',
        'refunds' => 'refunds_scope',
        'saas_invoices' => 'saas_invoices_scope',
        'saas_payments' => 'saas_payments_scope',
        'status_change_logs' => 'status_change_logs_scope',
        'supply_change_readings' => 'tenant_scope_supply_change_readings',
        'supply_changes' => 'tenant_scope_supply_changes',
        'tariff_versions' => 'tenant_scope_tariff_versions',
        'webhook_inbox' => 'webhook_inbox_org_scope',
    ];

    /**
     * Decision D52 (V7.25): tenant tables the design left without a policy.
     *
     * @var array<string, string>
     */
    public const array ADDED_POLICIES = [
        'billing_disputes' => 'tenant_scope_billing_disputes',
        'building_billing_settings' => 'tenant_scope_building_billing_settings',
        'building_membership_roles' => 'building_membership_roles_scope',
        'building_vendor_assignments' => 'tenant_scope_building_vendor_assignments',
        'membership_roles' => 'membership_roles_scope',
        'organization_memberships' => 'organization_memberships_scope',
        'tariff_retro_adjustments' => 'tenant_scope_tariff_retro_adjustments',
        'user_devices' => 'user_devices_scope',
    ];

    /**
     * Every table under row-level security: table => policy.
     *
     * @return array<string, string>
     */
    public static function policies(): array
    {
        $policies = [...self::DESIGN_POLICIES, ...self::ADDED_POLICIES];
        ksort($policies);

        return $policies;
    }
}
