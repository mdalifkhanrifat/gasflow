<?php

/**
 * Migration group 7, part 2: attachments and the ownership evidence link
 * (design 02 §4, V7.7).
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestRecords;

describe('attachments', function () {
    it('are registered as protected metering history', function () {
        // Arrange: migration group 7 has run

        // Act
        $entity = DB::table('lifecycle_entities')->where('entity_name', 'attachments')->first();

        // Assert
        expect([$entity->module, $entity->record_class, $entity->soft_delete_policy, $entity->hard_delete_policy])
            ->toBe(['metering', 'history', 'protected', 'forbidden']);
    });

    it('are private unless said otherwise', function () {
        // Arrange
        $id = TestRecords::attachment();

        // Act
        $attachment = DB::table('attachments')->find($id);

        // Assert
        expect([$attachment->storage_disk, $attachment->visibility])->toBe(['private', 'private']);
    });

    it('refuse a negative size', function () {
        // Arrange
        $row = ['size_bytes' => -1];

        // Act & Assert
        expect(fn () => TestRecords::attachment($row))->toThrow(QueryException::class, 'ck_attachments_size_bytes');
    });

    it('can never be deleted, because they are evidence', function () {
        // Arrange
        $id = TestRecords::attachment();

        // Act & Assert
        expect(fn () => DB::transaction(fn () => DB::table('attachments')->where('id', $id)->delete()))
            ->toThrow(QueryException::class);
        expect(DB::table('attachments')->where('id', $id)->value('is_deleted'))->toBeFalse();
    });
});

describe('ownership evidence', function () {
    it('links an ownership to its uploaded papers', function () {
        // Arrange
        $attachmentId = TestRecords::attachment();
        $row = ['building_id' => TestRecords::building(), 'owner_name' => 'Karim', 'valid_from' => '2026-01-01', 'evidence_attachment_id' => $attachmentId];

        // Act
        $id = DB::table('building_ownerships')->insertGetId($row);

        // Assert
        expect(DB::table('building_ownerships')->where('id', $id)->value('evidence_attachment_id'))->toBe($attachmentId);
    });

    it('refuses papers that were never uploaded', function () {
        // Arrange
        $row = ['building_id' => TestRecords::building(), 'owner_name' => 'Karim', 'valid_from' => '2026-01-01', 'evidence_attachment_id' => 999999999];

        // Act & Assert
        expect(fn () => DB::table('building_ownerships')->insert($row))
            ->toThrow(QueryException::class, 'fk_building_ownership_attachment');
    });
});
