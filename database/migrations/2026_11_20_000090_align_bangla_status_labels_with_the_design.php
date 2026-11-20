<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One Bangla label per status code across all tables, as the design keys
 * them (owner decision D53, design V7.26, group 16 question 3). The group 13
 * migrations gave 14 statuses their own wording, so the same code read
 * differently on different tables.
 */
return new class extends Migration
{
    /**
     * Table, code, design wording, the wording it had.
     */
    private const LABELS = [
        ['building_setup_tasks', 'blocked', 'অবরুদ্ধ', 'আটকে আছে'],
        ['gas_supply_receipts', 'recorded', 'লিপিবদ্ধ', 'রেকর্ডকৃত'],
        ['guest_access_links', 'revoked', 'প্রত্যাহারকৃত', 'প্রত্যাহার করা'],
        ['installation_installments', 'invoiced', 'বিল করা হয়েছে', 'বিলকৃত'],
        ['installation_licenses', 'grace', 'অতিরিক্ত সময়', 'অফলাইন ছাড়ের সময়'],
        ['installation_licenses', 'pending_activation', 'সক্রিয়করণ অপেক্ষমাণ', 'সক্রিয়করণের অপেক্ষায়'],
        ['license_activations', 'clone_suspected', 'কপি সন্দেহজনক', 'নকল কপির সন্দেহ'],
        ['license_activations', 'deactivated', 'নিষ্ক্রিয়কৃত', 'নিষ্ক্রিয় করা'],
        ['license_check_ins', 'recorded', 'লিপিবদ্ধ', 'রেকর্ডকৃত'],
        ['license_signing_keys', 'revoked', 'প্রত্যাহারকৃত', 'প্রত্যাহার করা'],
        ['license_signing_keys', 'rotated', 'পরিবর্তিত', 'বদলানো হয়েছে'],
        ['licenses', 'revoked', 'প্রত্যাহারকৃত', 'প্রত্যাহার করা'],
        ['organization_payment_gateways', 'disabled', 'নিষ্ক্রিয়', 'বন্ধ'],
        ['organization_sms_gateways', 'disabled', 'নিষ্ক্রিয়', 'বন্ধ'],
    ];

    public function up(): void
    {
        $this->label(2);
    }

    public function down(): void
    {
        $this->label(3);
    }

    private function label(int $wording): void
    {
        foreach (self::LABELS as $row) {
            DB::update(
                "UPDATE status_definitions SET translations = jsonb_set(translations, '{bn,label}', to_jsonb(?::text))
                 WHERE entity_name = ? AND code = ?",
                [$row[$wording], $row[0], $row[1]],
            );
        }
    }
};
