<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row-level security on the tenant tables, as designed (design 02 §15, final
 * form in 02b; owner decision D52, design V7.25, group 15 question 1). The
 * trail tables and access_sessions have theirs already.
 *
 * Every table gets ENABLE + FORCE (the owner role is filtered too) and one
 * policy for all commands:
 *
 * - organization scope: the row's organization is the context organization
 *   (ledger, audit log, idempotency keys, outbox, webhook inbox, customers).
 * - building scope (tenant_scope_*): the row's building is visible, which the
 *   buildings policy decides (own building, or an active access grant).
 * - parent scope: the row's parent is visible (invoice lines, credit notes,
 *   meter readings and their revisions, payment attempts, refunds, ...).
 *
 * Without an organization context nothing is visible (fail-closed). The
 * integrity checks run as gasflow_integrity (BYPASSRLS), so a hidden row
 * still counts for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE attachments ENABLE ROW LEVEL SECURITY;
            ALTER TABLE attachments FORCE ROW LEVEL SECURITY;
            CREATE POLICY attachments_scope ON public.attachments USING (((tenant_organization_id = public.app_current_organization_id()) OR ((building_id IS NOT NULL) AND (EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = attachments.building_id)))))) WITH CHECK (((tenant_organization_id = public.app_current_organization_id()) OR ((building_id IS NOT NULL) AND (EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = attachments.building_id))))));

            ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;
            ALTER TABLE audit_logs FORCE ROW LEVEL SECURITY;
            CREATE POLICY audit_logs_org_scope ON public.audit_logs USING ((organization_id = public.app_current_organization_id())) WITH CHECK ((organization_id = public.app_current_organization_id()));

            ALTER TABLE billing_cycles ENABLE ROW LEVEL SECURITY;
            ALTER TABLE billing_cycles FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_billing_cycles ON public.billing_cycles USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = billing_cycles.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = billing_cycles.building_id))));

            ALTER TABLE building_access_grants ENABLE ROW LEVEL SECURITY;
            ALTER TABLE building_access_grants FORCE ROW LEVEL SECURITY;
            CREATE POLICY access_grants_scope ON public.building_access_grants USING (((grantee_organization_id = public.app_current_organization_id()) OR (building_owner_organization_id = public.app_current_organization_id()))) WITH CHECK ((building_owner_organization_id = public.app_current_organization_id()));

            ALTER TABLE building_charge_rules ENABLE ROW LEVEL SECURITY;
            ALTER TABLE building_charge_rules FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_building_charge_rules ON public.building_charge_rules USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = building_charge_rules.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = building_charge_rules.building_id))));

            ALTER TABLE building_memberships ENABLE ROW LEVEL SECURITY;
            ALTER TABLE building_memberships FORCE ROW LEVEL SECURITY;
            CREATE POLICY building_memberships_scope ON public.building_memberships USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = building_memberships.building_id))));

            ALTER TABLE building_ownerships ENABLE ROW LEVEL SECURITY;
            ALTER TABLE building_ownerships FORCE ROW LEVEL SECURITY;
            CREATE POLICY building_ownerships_scope ON public.building_ownerships USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = building_ownerships.building_id))));

            ALTER TABLE building_setup_tasks ENABLE ROW LEVEL SECURITY;
            ALTER TABLE building_setup_tasks FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_building_setup_tasks ON public.building_setup_tasks USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = building_setup_tasks.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = building_setup_tasks.building_id))));

            ALTER TABLE building_subscriptions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE building_subscriptions FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_building_subscriptions ON public.building_subscriptions USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = building_subscriptions.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = building_subscriptions.building_id))));

            ALTER TABLE buildings ENABLE ROW LEVEL SECURITY;
            ALTER TABLE buildings FORCE ROW LEVEL SECURITY;
            CREATE POLICY buildings_tenant_scope ON public.buildings USING (((tenant_organization_id = public.app_current_organization_id()) OR (EXISTS ( SELECT 1
               FROM public.building_access_grants g
              WHERE ((g.building_id = buildings.id) AND (g.grantee_organization_id = public.app_current_organization_id()) AND ((g.status)::text = 'active'::text) AND (g.valid_from <= CURRENT_DATE) AND ((g.valid_to IS NULL) OR (g.valid_to >= CURRENT_DATE))))))) WITH CHECK ((tenant_organization_id = public.app_current_organization_id()));

            ALTER TABLE credit_notes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE credit_notes FORCE ROW LEVEL SECURITY;
            CREATE POLICY credit_notes_scope ON public.credit_notes USING ((EXISTS ( SELECT 1
               FROM public.invoices i
              WHERE (i.id = credit_notes.invoice_id))));

            ALTER TABLE customers ENABLE ROW LEVEL SECURITY;
            ALTER TABLE customers FORCE ROW LEVEL SECURITY;
            CREATE POLICY customers_scope ON public.customers USING ((tenant_organization_id = public.app_current_organization_id())) WITH CHECK ((tenant_organization_id = public.app_current_organization_id()));

            ALTER TABLE financial_accounts ENABLE ROW LEVEL SECURITY;
            ALTER TABLE financial_accounts FORCE ROW LEVEL SECURITY;
            CREATE POLICY financial_accounts_org_scope ON public.financial_accounts USING ((organization_id = public.app_current_organization_id())) WITH CHECK ((organization_id = public.app_current_organization_id()));

            ALTER TABLE financial_journal_lines ENABLE ROW LEVEL SECURITY;
            ALTER TABLE financial_journal_lines FORCE ROW LEVEL SECURITY;
            CREATE POLICY financial_journal_lines_org_scope ON public.financial_journal_lines USING ((organization_id = public.app_current_organization_id())) WITH CHECK ((organization_id = public.app_current_organization_id()));

            ALTER TABLE financial_journals ENABLE ROW LEVEL SECURITY;
            ALTER TABLE financial_journals FORCE ROW LEVEL SECURITY;
            CREATE POLICY financial_journals_org_scope ON public.financial_journals USING ((organization_id = public.app_current_organization_id())) WITH CHECK ((organization_id = public.app_current_organization_id()));

            ALTER TABLE financial_transactions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE financial_transactions FORCE ROW LEVEL SECURITY;
            CREATE POLICY financial_transactions_org_scope ON public.financial_transactions USING ((organization_id = public.app_current_organization_id())) WITH CHECK ((organization_id = public.app_current_organization_id()));

            ALTER TABLE flat_occupancies ENABLE ROW LEVEL SECURITY;
            ALTER TABLE flat_occupancies FORCE ROW LEVEL SECURITY;
            CREATE POLICY flat_occupancies_scope ON public.flat_occupancies USING ((EXISTS ( SELECT 1
               FROM public.flats f
              WHERE (f.id = flat_occupancies.flat_id))));

            ALTER TABLE flats ENABLE ROW LEVEL SECURITY;
            ALTER TABLE flats FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_flats ON public.flats USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = flats.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = flats.building_id))));

            ALTER TABLE gas_supply_receipts ENABLE ROW LEVEL SECURITY;
            ALTER TABLE gas_supply_receipts FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_gas_supply_receipts ON public.gas_supply_receipts USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = gas_supply_receipts.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = gas_supply_receipts.building_id))));

            ALTER TABLE guest_access_links ENABLE ROW LEVEL SECURITY;
            ALTER TABLE guest_access_links FORCE ROW LEVEL SECURITY;
            CREATE POLICY guest_access_links_scope ON public.guest_access_links USING ((EXISTS ( SELECT 1
               FROM public.invoices i
              WHERE (i.id = guest_access_links.invoice_id))));

            ALTER TABLE idempotency_keys ENABLE ROW LEVEL SECURITY;
            ALTER TABLE idempotency_keys FORCE ROW LEVEL SECURITY;
            CREATE POLICY idempotency_keys_org_scope ON public.idempotency_keys USING ((organization_id = public.app_current_organization_id())) WITH CHECK ((organization_id = public.app_current_organization_id()));

            ALTER TABLE installation_agreements ENABLE ROW LEVEL SECURITY;
            ALTER TABLE installation_agreements FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_installation_agreements ON public.installation_agreements USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = installation_agreements.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = installation_agreements.building_id))));

            ALTER TABLE installation_installments ENABLE ROW LEVEL SECURITY;
            ALTER TABLE installation_installments FORCE ROW LEVEL SECURITY;
            CREATE POLICY installation_installments_scope ON public.installation_installments USING ((EXISTS ( SELECT 1
               FROM public.installation_agreements ia
              WHERE (ia.id = installation_installments.installation_agreement_id))));

            ALTER TABLE integration_delivery_attempts ENABLE ROW LEVEL SECURITY;
            ALTER TABLE integration_delivery_attempts FORCE ROW LEVEL SECURITY;
            CREATE POLICY integration_delivery_attempts_scope ON public.integration_delivery_attempts USING ((EXISTS ( SELECT 1
               FROM public.integration_outbox o
              WHERE (o.id = integration_delivery_attempts.outbox_id))));

            ALTER TABLE integration_outbox ENABLE ROW LEVEL SECURITY;
            ALTER TABLE integration_outbox FORCE ROW LEVEL SECURITY;
            CREATE POLICY integration_outbox_org_scope ON public.integration_outbox USING ((organization_id = public.app_current_organization_id())) WITH CHECK ((organization_id = public.app_current_organization_id()));

            ALTER TABLE invoice_lines ENABLE ROW LEVEL SECURITY;
            ALTER TABLE invoice_lines FORCE ROW LEVEL SECURITY;
            CREATE POLICY invoice_lines_scope ON public.invoice_lines USING ((EXISTS ( SELECT 1
               FROM public.invoices i
              WHERE (i.id = invoice_lines.invoice_id))));

            ALTER TABLE invoices ENABLE ROW LEVEL SECURITY;
            ALTER TABLE invoices FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_invoices ON public.invoices USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = invoices.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = invoices.building_id))));

            ALTER TABLE meter_assignments ENABLE ROW LEVEL SECURITY;
            ALTER TABLE meter_assignments FORCE ROW LEVEL SECURITY;
            CREATE POLICY meter_assignments_scope ON public.meter_assignments USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = meter_assignments.building_id))));

            ALTER TABLE meter_events ENABLE ROW LEVEL SECURITY;
            ALTER TABLE meter_events FORCE ROW LEVEL SECURITY;
            CREATE POLICY meter_events_scope ON public.meter_events USING ((EXISTS ( SELECT 1
               FROM public.meters m
              WHERE (m.id = meter_events.meter_id))));

            ALTER TABLE meter_occupancy_assignments ENABLE ROW LEVEL SECURITY;
            ALTER TABLE meter_occupancy_assignments FORCE ROW LEVEL SECURITY;
            CREATE POLICY meter_occupancy_assignments_scope ON public.meter_occupancy_assignments USING ((EXISTS ( SELECT 1
               FROM (public.meter_assignments ma
                 JOIN public.meters m ON ((m.id = ma.meter_id)))
              WHERE (ma.id = meter_occupancy_assignments.meter_assignment_id))));

            ALTER TABLE meter_reading_revisions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE meter_reading_revisions FORCE ROW LEVEL SECURITY;
            CREATE POLICY meter_reading_revisions_scope ON public.meter_reading_revisions USING ((EXISTS ( SELECT 1
               FROM public.meter_readings mr
              WHERE (mr.id = meter_reading_revisions.meter_reading_id))));

            ALTER TABLE meter_readings ENABLE ROW LEVEL SECURITY;
            ALTER TABLE meter_readings FORCE ROW LEVEL SECURITY;
            CREATE POLICY meter_readings_scope ON public.meter_readings USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = meter_readings.building_id))));

            ALTER TABLE meter_reconciliations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE meter_reconciliations FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_meter_reconciliations ON public.meter_reconciliations USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = meter_reconciliations.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = meter_reconciliations.building_id))));

            ALTER TABLE meters ENABLE ROW LEVEL SECURITY;
            ALTER TABLE meters FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_meters ON public.meters USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = meters.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = meters.building_id))));

            ALTER TABLE notification_templates ENABLE ROW LEVEL SECURITY;
            ALTER TABLE notification_templates FORCE ROW LEVEL SECURITY;
            CREATE POLICY notification_templates_scope ON public.notification_templates USING (((organization_id IS NULL) OR (organization_id = public.app_current_organization_id()))) WITH CHECK ((organization_id = public.app_current_organization_id()));

            ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;
            ALTER TABLE notifications FORCE ROW LEVEL SECURITY;
            CREATE POLICY notifications_scope ON public.notifications USING (((organization_id = public.app_current_organization_id()) OR ((building_id IS NOT NULL) AND (EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = notifications.building_id)))))) WITH CHECK (((organization_id = public.app_current_organization_id()) OR ((building_id IS NOT NULL) AND (EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = notifications.building_id))))));

            ALTER TABLE organization_payment_gateways ENABLE ROW LEVEL SECURITY;
            ALTER TABLE organization_payment_gateways FORCE ROW LEVEL SECURITY;
            CREATE POLICY payment_gateway_config_scope ON public.organization_payment_gateways USING (((organization_id = public.app_current_organization_id()) OR ((building_id IS NOT NULL) AND (EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = organization_payment_gateways.building_id)))))) WITH CHECK (((organization_id = public.app_current_organization_id()) OR ((building_id IS NOT NULL) AND (EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = organization_payment_gateways.building_id))))));

            ALTER TABLE organization_sms_gateways ENABLE ROW LEVEL SECURITY;
            ALTER TABLE organization_sms_gateways FORCE ROW LEVEL SECURITY;
            CREATE POLICY sms_gateway_config_scope ON public.organization_sms_gateways USING (((organization_id = public.app_current_organization_id()) OR ((building_id IS NOT NULL) AND (EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = organization_sms_gateways.building_id)))))) WITH CHECK (((organization_id = public.app_current_organization_id()) OR ((building_id IS NOT NULL) AND (EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = organization_sms_gateways.building_id))))));

            ALTER TABLE payment_allocations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payment_allocations FORCE ROW LEVEL SECURITY;
            CREATE POLICY payment_allocations_scope ON public.payment_allocations USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = payment_allocations.building_id))));

            ALTER TABLE payment_attempts ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payment_attempts FORCE ROW LEVEL SECURITY;
            CREATE POLICY payment_attempts_scope ON public.payment_attempts USING ((EXISTS ( SELECT 1
               FROM public.payments p
              WHERE (p.id = payment_attempts.payment_id))));

            ALTER TABLE payments ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payments FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_payments ON public.payments USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = payments.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = payments.building_id))));

            ALTER TABLE pipeline_zones ENABLE ROW LEVEL SECURITY;
            ALTER TABLE pipeline_zones FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_pipeline_zones ON public.pipeline_zones USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = pipeline_zones.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = pipeline_zones.building_id))));

            ALTER TABLE portal_access_policies ENABLE ROW LEVEL SECURITY;
            ALTER TABLE portal_access_policies FORCE ROW LEVEL SECURITY;
            CREATE POLICY portal_access_policy_scope ON public.portal_access_policies USING (((organization_id = public.app_current_organization_id()) OR ((building_id IS NOT NULL) AND (EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = portal_access_policies.building_id)))))) WITH CHECK (((organization_id = public.app_current_organization_id()) OR ((building_id IS NOT NULL) AND (EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = portal_access_policies.building_id))))));

            ALTER TABLE reading_approvals ENABLE ROW LEVEL SECURITY;
            ALTER TABLE reading_approvals FORCE ROW LEVEL SECURITY;
            CREATE POLICY reading_approvals_scope ON public.reading_approvals USING ((EXISTS ( SELECT 1
               FROM public.meter_readings mr
              WHERE (mr.id = reading_approvals.meter_reading_id))));

            ALTER TABLE reading_periods ENABLE ROW LEVEL SECURITY;
            ALTER TABLE reading_periods FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_reading_periods ON public.reading_periods USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = reading_periods.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = reading_periods.building_id))));

            ALTER TABLE refunds ENABLE ROW LEVEL SECURITY;
            ALTER TABLE refunds FORCE ROW LEVEL SECURITY;
            CREATE POLICY refunds_scope ON public.refunds USING ((EXISTS ( SELECT 1
               FROM public.payments p
              WHERE (p.id = refunds.payment_id))));

            ALTER TABLE saas_invoices ENABLE ROW LEVEL SECURITY;
            ALTER TABLE saas_invoices FORCE ROW LEVEL SECURITY;
            CREATE POLICY saas_invoices_scope ON public.saas_invoices USING ((EXISTS ( SELECT 1
               FROM public.building_subscriptions bs
              WHERE (bs.id = saas_invoices.building_subscription_id))));

            ALTER TABLE saas_payments ENABLE ROW LEVEL SECURITY;
            ALTER TABLE saas_payments FORCE ROW LEVEL SECURITY;
            CREATE POLICY saas_payments_scope ON public.saas_payments USING ((EXISTS ( SELECT 1
               FROM public.saas_invoices si
              WHERE (si.id = saas_payments.saas_invoice_id))));

            ALTER TABLE supply_change_readings ENABLE ROW LEVEL SECURITY;
            ALTER TABLE supply_change_readings FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_supply_change_readings ON public.supply_change_readings USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = supply_change_readings.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = supply_change_readings.building_id))));

            ALTER TABLE supply_changes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE supply_changes FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_supply_changes ON public.supply_changes USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = supply_changes.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = supply_changes.building_id))));

            ALTER TABLE tariff_versions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE tariff_versions FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_scope_tariff_versions ON public.tariff_versions USING ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = tariff_versions.building_id)))) WITH CHECK ((EXISTS ( SELECT 1
               FROM public.buildings b
              WHERE (b.id = tariff_versions.building_id))));

            ALTER TABLE webhook_inbox ENABLE ROW LEVEL SECURITY;
            ALTER TABLE webhook_inbox FORCE ROW LEVEL SECURITY;
            CREATE POLICY webhook_inbox_org_scope ON public.webhook_inbox USING ((organization_id = public.app_current_organization_id())) WITH CHECK ((organization_id = public.app_current_organization_id()));
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS attachments_scope ON attachments;
            ALTER TABLE attachments NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE attachments DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS audit_logs_org_scope ON audit_logs;
            ALTER TABLE audit_logs NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE audit_logs DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_billing_cycles ON billing_cycles;
            ALTER TABLE billing_cycles NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE billing_cycles DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS access_grants_scope ON building_access_grants;
            ALTER TABLE building_access_grants NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE building_access_grants DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_building_charge_rules ON building_charge_rules;
            ALTER TABLE building_charge_rules NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE building_charge_rules DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS building_memberships_scope ON building_memberships;
            ALTER TABLE building_memberships NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE building_memberships DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS building_ownerships_scope ON building_ownerships;
            ALTER TABLE building_ownerships NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE building_ownerships DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_building_setup_tasks ON building_setup_tasks;
            ALTER TABLE building_setup_tasks NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE building_setup_tasks DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_building_subscriptions ON building_subscriptions;
            ALTER TABLE building_subscriptions NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE building_subscriptions DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS buildings_tenant_scope ON buildings;
            ALTER TABLE buildings NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE buildings DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS credit_notes_scope ON credit_notes;
            ALTER TABLE credit_notes NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE credit_notes DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS customers_scope ON customers;
            ALTER TABLE customers NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE customers DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS financial_accounts_org_scope ON financial_accounts;
            ALTER TABLE financial_accounts NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE financial_accounts DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS financial_journal_lines_org_scope ON financial_journal_lines;
            ALTER TABLE financial_journal_lines NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE financial_journal_lines DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS financial_journals_org_scope ON financial_journals;
            ALTER TABLE financial_journals NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE financial_journals DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS financial_transactions_org_scope ON financial_transactions;
            ALTER TABLE financial_transactions NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE financial_transactions DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS flat_occupancies_scope ON flat_occupancies;
            ALTER TABLE flat_occupancies NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE flat_occupancies DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_flats ON flats;
            ALTER TABLE flats NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE flats DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_gas_supply_receipts ON gas_supply_receipts;
            ALTER TABLE gas_supply_receipts NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE gas_supply_receipts DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS guest_access_links_scope ON guest_access_links;
            ALTER TABLE guest_access_links NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE guest_access_links DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS idempotency_keys_org_scope ON idempotency_keys;
            ALTER TABLE idempotency_keys NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE idempotency_keys DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_installation_agreements ON installation_agreements;
            ALTER TABLE installation_agreements NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE installation_agreements DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS installation_installments_scope ON installation_installments;
            ALTER TABLE installation_installments NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE installation_installments DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS integration_delivery_attempts_scope ON integration_delivery_attempts;
            ALTER TABLE integration_delivery_attempts NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE integration_delivery_attempts DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS integration_outbox_org_scope ON integration_outbox;
            ALTER TABLE integration_outbox NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE integration_outbox DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS invoice_lines_scope ON invoice_lines;
            ALTER TABLE invoice_lines NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE invoice_lines DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_invoices ON invoices;
            ALTER TABLE invoices NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE invoices DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS meter_assignments_scope ON meter_assignments;
            ALTER TABLE meter_assignments NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE meter_assignments DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS meter_events_scope ON meter_events;
            ALTER TABLE meter_events NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE meter_events DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS meter_occupancy_assignments_scope ON meter_occupancy_assignments;
            ALTER TABLE meter_occupancy_assignments NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE meter_occupancy_assignments DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS meter_reading_revisions_scope ON meter_reading_revisions;
            ALTER TABLE meter_reading_revisions NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE meter_reading_revisions DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS meter_readings_scope ON meter_readings;
            ALTER TABLE meter_readings NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE meter_readings DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_meter_reconciliations ON meter_reconciliations;
            ALTER TABLE meter_reconciliations NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE meter_reconciliations DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_meters ON meters;
            ALTER TABLE meters NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE meters DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS notification_templates_scope ON notification_templates;
            ALTER TABLE notification_templates NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE notification_templates DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS notifications_scope ON notifications;
            ALTER TABLE notifications NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE notifications DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS payment_gateway_config_scope ON organization_payment_gateways;
            ALTER TABLE organization_payment_gateways NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE organization_payment_gateways DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS sms_gateway_config_scope ON organization_sms_gateways;
            ALTER TABLE organization_sms_gateways NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE organization_sms_gateways DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS payment_allocations_scope ON payment_allocations;
            ALTER TABLE payment_allocations NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE payment_allocations DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS payment_attempts_scope ON payment_attempts;
            ALTER TABLE payment_attempts NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE payment_attempts DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_payments ON payments;
            ALTER TABLE payments NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE payments DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_pipeline_zones ON pipeline_zones;
            ALTER TABLE pipeline_zones NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE pipeline_zones DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS portal_access_policy_scope ON portal_access_policies;
            ALTER TABLE portal_access_policies NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE portal_access_policies DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS reading_approvals_scope ON reading_approvals;
            ALTER TABLE reading_approvals NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE reading_approvals DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_reading_periods ON reading_periods;
            ALTER TABLE reading_periods NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE reading_periods DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS refunds_scope ON refunds;
            ALTER TABLE refunds NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE refunds DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS saas_invoices_scope ON saas_invoices;
            ALTER TABLE saas_invoices NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE saas_invoices DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS saas_payments_scope ON saas_payments;
            ALTER TABLE saas_payments NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE saas_payments DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_supply_change_readings ON supply_change_readings;
            ALTER TABLE supply_change_readings NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE supply_change_readings DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_supply_changes ON supply_changes;
            ALTER TABLE supply_changes NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE supply_changes DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS tenant_scope_tariff_versions ON tariff_versions;
            ALTER TABLE tariff_versions NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE tariff_versions DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS webhook_inbox_org_scope ON webhook_inbox;
            ALTER TABLE webhook_inbox NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE webhook_inbox DISABLE ROW LEVEL SECURITY;
            SQL);
    }
};
