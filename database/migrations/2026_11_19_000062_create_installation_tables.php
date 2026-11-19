<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 13, part 1: installation agreements and their installment
 * (EMI) schedule (design 02 §12, V7.7, V7.9).
 *
 * - installation_agreements: a gas installation of a building, optionally
 *   for one customer, paid back in installment_count parts from start_date.
 * - installation_installments: one row per part, unique per agreement and
 *   number; a part may be billed through an invoice line and tracks what was
 *   paid of it (never more than its amount).
 *
 * Both are financial records: protected, never hidden. Their statuses are
 * not enforced as transitions by the design. Row-level security comes with
 * group 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_agreements', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->string('agreement_no', 100)->unique();
            $table->text('description');
            $table->decimal('principal_amount', 14, 2);
            $table->char('currency', 3)->default('BDT');
            $table->smallInteger('installment_count');
            $table->date('start_date');
            $table->string('status', 24)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('installation_installments', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('installation_agreement_id')->constrained();
            $table->smallInteger('installment_no');
            $table->date('due_date');
            $table->decimal('amount', 14, 2);
            $table->string('status', 24)->default('scheduled');
            $table->foreignId('invoice_line_id')->nullable()->constrained();
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['installation_agreement_id', 'installment_no']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE installation_agreements
              ADD CONSTRAINT ck_installation_agreements_principal_amount CHECK (principal_amount > 0),
              ADD CONSTRAINT ck_installation_agreements_installment_count CHECK (installment_count > 0),
              ADD CONSTRAINT ck_installation_agreements_status
                CHECK (status IN ('draft','active','completed','cancelled','defaulted'));

            ALTER TABLE installation_installments
              ADD CONSTRAINT ck_installation_installments_amount CHECK (amount > 0),
              ADD CONSTRAINT ck_installation_installments_paid_amount CHECK (paid_amount >= 0 AND paid_amount <= amount),
              ADD CONSTRAINT ck_installation_installments_status
                CHECK (status IN ('scheduled','invoiced','partially_paid','paid','waived','overdue'));
            -- Open parts by due date, for the reminder and overdue jobs.
            CREATE INDEX idx_installments_due_open
              ON installation_installments(due_date)
              WHERE status IN ('scheduled','invoiced','partially_paid','overdue');
            SQL);

        // Lifecycle policies exactly as design V7.7 (no enforced transitions).
        LifecycleSchema::attach('installation_agreements', module: 'installations', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'draft');
        LifecycleSchema::attach('installation_installments', module: 'installations', class: RecordClass::Financial,
            softDelete: SoftDeletePolicy::Protected, defaultStatus: 'scheduled');

        DB::unprepared(<<<'SQL'
            INSERT INTO status_definitions(entity_name, code, label, tone, implies_active, is_initial, is_terminal, sort_order, translations)
            VALUES ('installation_agreements', 'draft',     'Draft',     'neutral', NULL,  true,  false, 10, '{"bn":{"label":"খসড়া"}}'),
                   ('installation_agreements', 'active',    'Active',    'success', true,  true,  false, 20, '{"bn":{"label":"সক্রিয়"}}'),
                   ('installation_agreements', 'completed', 'Completed', 'success', NULL,  false, true,  30, '{"bn":{"label":"সম্পন্ন"}}'),
                   ('installation_agreements', 'cancelled', 'Cancelled', 'muted',   false, false, true,  40, '{"bn":{"label":"বাতিল"}}'),
                   ('installation_agreements', 'defaulted', 'Defaulted', 'danger',  NULL,  false, false, 50, '{"bn":{"label":"খেলাপি"}}'),
                   ('installation_installments', 'scheduled',      'Scheduled',      'neutral', NULL, true,  false, 10, '{"bn":{"label":"নির্ধারিত"}}'),
                   ('installation_installments', 'invoiced',       'Invoiced',       'info',    NULL, false, false, 20, '{"bn":{"label":"বিলকৃত"}}'),
                   ('installation_installments', 'partially_paid', 'Partially paid', 'warning', NULL, false, false, 30, '{"bn":{"label":"আংশিক পরিশোধিত"}}'),
                   ('installation_installments', 'paid',           'Paid',           'success', NULL, false, true,  40, '{"bn":{"label":"পরিশোধিত"}}'),
                   ('installation_installments', 'waived',         'Waived',         'muted',   NULL, false, true,  50, '{"bn":{"label":"মওকুফ"}}'),
                   ('installation_installments', 'overdue',        'Overdue',        'danger',  NULL, false, false, 60, '{"bn":{"label":"মেয়াদোত্তীর্ণ বকেয়া"}}');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM status_definitions WHERE entity_name IN ('installation_agreements', 'installation_installments');
            DELETE FROM lifecycle_entities WHERE entity_name IN ('installation_agreements', 'installation_installments');
            SQL);

        Schema::dropIfExists('installation_installments');
        Schema::dropIfExists('installation_agreements');
    }
};
