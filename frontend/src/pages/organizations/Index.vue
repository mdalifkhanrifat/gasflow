<script lang="ts">
import { createPreviewRecords } from '@/lib/createPreviewRecords';

interface Organization {
    name: string;
    type: 'module.gas_vendor' | 'module.management';
    contact: string;
    buildings: number;
    members: number;
    status: 'module.active' | 'module.pending';
    tone: 'success' | 'warning';
}

const {
    rows: organizations,
    add: addOrganization,
    update: updateRecord,
    remove: removeRecord,
} = createPreviewRecords<Organization>(
    [
        {
            name: 'Northstar Gas Services',
            type: 'module.gas_vendor',
            contact: 'Jahid Hasan',
            buildings: 3,
            members: 12,
            status: 'module.active',
            tone: 'success',
        },
        {
            name: 'Green View Management',
            type: 'module.management',
            contact: 'Sadia Rahman',
            buildings: 2,
            members: 8,
            status: 'module.active',
            tone: 'success',
        },
        {
            name: 'Metro Utility Ltd.',
            type: 'module.gas_vendor',
            contact: 'Mahmud Karim',
            buildings: 5,
            members: 19,
            status: 'module.pending',
            tone: 'warning',
        },
    ],
    (record) => record.name,
);
</script>

<script setup lang="ts">
import { computed, watchEffect } from 'vue';
import ModuleListing from '@/components/app/modules/ModuleListing.vue';
import type { RecordCreateDefinition } from '@/components/app/modules/RecordCreateDialog.vue';
import { useLocale } from '@/composables/useLocale';
import { formatNumber } from '@/lib/format';

const { locale, t } = useLocale();
watchEffect(() => {
    document.title = t('navigation.organizations') + ' - GasFlow';
});

const previewActions = {
    editColumn: 2,
    onEdit: (key: string, value: string) =>
        updateRecord(key, { contact: value }),
    onRemove: removeRecord,
};

const createRecord = computed<RecordCreateDefinition>(() => ({
    label: t('module.add_organization'),
    fields: [
        { name: 'name', label: t('module.organization') },
        { name: 'contact', label: t('module.contact') },
        {
            name: 'type',
            label: t('module.type'),
            defaultValue: 'module.gas_vendor',
            options: [
                { value: 'module.gas_vendor', label: t('module.gas_vendor') },
                { value: 'module.management', label: t('module.management') },
            ],
        },
    ],
    save: ({ name, contact, type }) => {
        if (
            organizations.value.some(
                (record) =>
                    record.name.toLocaleLowerCase() ===
                    name.toLocaleLowerCase(),
            )
        ) {
            return t('module.existing_organization');
        }
        addOrganization({
            name,
            contact,
            type:
                type === 'module.management'
                    ? 'module.management'
                    : 'module.gas_vendor',
            buildings: 0,
            members: 0,
            status: 'module.pending',
            tone: 'warning',
        });
    },
}));

const rows = computed(() =>
    organizations.value.map((row) => ({
        cells: [
            row.name,
            t(row.type),
            row.contact,
            formatNumber(row.buildings, locale.value),
            formatNumber(row.members, locale.value),
        ],
        sortValues: { 3: row.buildings, 4: row.members },
        statusCode: row.status,
        status: t(row.status),
        tone: row.tone,
    })),
);
</script>

<template>
    <ModuleListing
        export-filename="organizations.csv"
        :title="t('navigation.organizations')"
        :columns="[
            t('module.organization'),
            t('module.type'),
            t('module.contact'),
            t('module.building_count'),
            t('module.members'),
        ]"
        :rows="rows"
        :preview-actions="previewActions"
        :create-record="createRecord"
        :numeric-columns="[3, 4]"
    />
</template>
