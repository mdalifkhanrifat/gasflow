<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 10, part 4: credit notes and billing disputes (design 02
 * §7 and §9, V7.7, V7.9).
 *
 * - credit_notes: money given back on an invoice (also the credit side of a
 *   retro rate that went down). pending_approval → approved → applied, or
 *   rejected / void, is enforced. Protected money records.
 * - billing_disputes: a customer's complaint about an invoice, worked from
 *   open to resolved, rejected or closed. It stays in the invoice's building
 *   (composite key, D42). Protected workflow; transitions are not enforced
 *   (design V7.7).
 *
 * Row-level security for both comes with group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('invoice_id')->constrained();
            $table->string('credit_note_no', 100)->unique();
            $table->decimal('amount', 14, 2);
            $table->text('reason');
            $table->string('status', 24)->default('pending_approval');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('billing_disputes', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('invoice_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->string('dispute_no', 100)->unique();
            $table->string('category', 60);
            $table->text('description');
            $table->string('status', 24)->default('open');
            $table->text('resolution')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['invoice_id', 'building_id'], 'fk_billing_dispute_invoice')
                ->references(['id', 'building_id'])->on('invoices');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE credit_notes
              ADD CONSTRAINT ck_credit_notes_amount CHECK (amount > 0);
            SQL);

        // Lifecycle policies exactly as design V7.7; credit note transitions as V7.9.
        LifecycleSchema::attach('credit_notes', module: 'billing', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected, enforceTransitions: true, defaultStatus: 'pending_approval');
        LifecycleSchema::attach('billing_disputes', module: 'billing', class: RecordClass::Workflow,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'open');

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('credit_notes', 'pending_approval', 'Pending approval', 'warning', NULL, true,  false, 10, '{"bn":{"label":"অনুমোদন অপেক্ষমাণ"}}'),
                   ('credit_notes', 'approved',         'Approved',         'info',    NULL, false, false, 20, '{"bn":{"label":"অনুমোদিত"}}'),
                   ('credit_notes', 'applied',          'Applied',          'success', NULL, false, true,  30, '{"bn":{"label":"প্রয়োগকৃত"}}'),
                   ('credit_notes', 'rejected',         'Rejected',         'danger',  NULL, false, true,  40, '{"bn":{"label":"প্রত্যাখ্যাত"}}'),
                   ('credit_notes', 'void',             'Void',             'muted',   NULL, false, true,  50, '{"bn":{"label":"বাতিলকৃত"}}'),
                   ('billing_disputes', 'open',          'Open',          'danger',  NULL, true,  false, 10, '{"bn":{"label":"খোলা"}}'),
                   ('billing_disputes', 'investigating', 'Investigating', 'warning', NULL, false, false, 20, '{"bn":{"label":"তদন্তাধীন"}}'),
                   ('billing_disputes', 'resolved',      'Resolved',      'success', NULL, false, true,  30, '{"bn":{"label":"নিষ্পন্ন"}}'),
                   ('billing_disputes', 'rejected',      'Rejected',      'muted',   NULL, false, true,  40, '{"bn":{"label":"প্রত্যাখ্যাত"}}'),
                   ('billing_disputes', 'closed',        'Closed',        'muted',   NULL, false, true,  50, '{"bn":{"label":"বন্ধ"}}');

            INSERT INTO status_transitions(entity_name, from_code, to_code, permission_code, requires_reason)
            VALUES ('credit_notes', 'pending_approval', 'approved', 'credit_note.approve', false),
                   ('credit_notes', 'pending_approval', 'rejected', 'credit_note.approve', true),
                   ('credit_notes', 'approved',         'applied',  NULL,                  false),
                   ('credit_notes', 'approved',         'void',     'credit_note.void',    true);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_transitions WHERE entity_name = 'credit_notes';
            DELETE FROM status_definitions WHERE entity_name IN ('credit_notes', 'billing_disputes');
            DELETE FROM lifecycle_entities WHERE entity_name IN ('credit_notes', 'billing_disputes');
            SQL);

        Schema::dropIfExists('billing_disputes');
        Schema::dropIfExists('credit_notes');
    }
};
