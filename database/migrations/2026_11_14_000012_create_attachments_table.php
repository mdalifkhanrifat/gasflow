<?php

use App\Enums\Lifecycle\RecordClass;
use App\Enums\Lifecycle\SoftDeletePolicy;
use App\Services\Lifecycle\LifecycleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 7, part 2: uploaded files (design 02 §4, V7.7).
 *
 * A row describes a file in private storage (disk, object key, size, hash);
 * the file itself never sits in the database. Attachments are evidence
 * (ownership papers, meter photos, payment slips), so they are history and
 * are never hidden. The ownership evidence FK from group 4 is added here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('tenant_organization_id')->nullable()->constrained('organizations');
            $table->foreignId('building_id')->nullable()->constrained();
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->string('storage_disk', 40)->default('private');
            $table->string('object_key', 500);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('visibility', 20)->default('private');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE attachments
              ADD CONSTRAINT ck_attachments_size_bytes CHECK (size_bytes IS NULL OR size_bytes >= 0);

            ALTER TABLE building_ownerships
              ADD CONSTRAINT fk_building_ownership_attachment
                FOREIGN KEY (evidence_attachment_id) REFERENCES attachments(id);
            SQL);

        // Lifecycle policy exactly as design V7.7 (generic status set).
        LifecycleSchema::attach('attachments', module: 'metering', class: RecordClass::History, softDelete: SoftDeletePolicy::Protected);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE building_ownerships DROP CONSTRAINT IF EXISTS fk_building_ownership_attachment;
            DELETE FROM lifecycle_entities WHERE entity_name = 'attachments';
            SQL);

        Schema::dropIfExists('attachments');
    }
};
