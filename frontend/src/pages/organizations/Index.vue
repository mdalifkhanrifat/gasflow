<script lang="ts">
import { createPreviewRecords } from '@/lib/createPreviewRecords';

interface Organization {
    name: string;
    type: 'module.gas_vendor' | 'module.management';
    contact: string;
    buildings: number;
    members: number;
    // organizations statuses in status_definitions.
    status: 'active' | 'pending';
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
            status: 'active',
        },
        {
            name: 'Green View Management',
            type: 'module.management',
            contact: 'Sadia Rahman',
            buildings: 2,
            members: 8,
            status: 'active',
        },
        {
            name: 'Metro Utility Ltd.',
            type: 'module.gas_vendor',
            contact: 'Mahmud Karim',
            buildings: 5,
            members: 19,
            status: 'pending',
        },
    ],
    (record) => record.name,
);
</script>

<script setup lang="ts">
import { computed, watchEffect } from 'vue';
import ModuleListing from '@/components/app/modules/ModuleListing.vue';
import {
    type TableColumn,
    usePreviewTableSource,
} from '@/components/app/tables/columns';
import type { RecordCreateDefinition } from '@/components/app/modules/RecordCreateDialog.vue';
import { useLocale } from '@/composables/useLocale';

const { t } = useLocale();
watchEffect(() => {
    document.title = t('navigation.organizations') + ' - GasFlow';
});

const previewActions = {
    editColumn: 'contact',
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
            status: 'pending',
        });
    },
}));

const columns = computed<TableColumn<Organization>[]>(() => [
    {
        key: 'name',
        label: t('module.organization'),
        value: (row) => row.name,
        sortable: true,
    },
    {
        key: 'type',
        label: t('module.type'),
        value: (row) => t(row.type),
        sortable: true,
    },
    {
        key: 'contact',
        label: t('module.contact'),
        value: (row) => row.contact,
        sortable: true,
    },
    {
        key: 'buildings',
        label: t('module.building_count'),
        value: (row) => row.buildings,
        type: 'number',
        sortable: true,
    },
    {
        key: 'members',
        label: t('module.members'),
        value: (row) => row.members,
        type: 'number',
        sortable: true,
    },
    {
        key: 'status',
        label: t('module.status'),
        value: (row) => row.status,
        type: 'status',
        statusEntity: 'organizations',
        sortable: true,
    },
]);
const source = usePreviewTableSource(
    () => organizations.value,
    () => columns.value,
);
</script>

<template>
    <ModuleListing
        export-filename="organizations.csv"
        :title="t('navigation.organizations')"
        :columns="columns"
        :source="source"
        :row-key="(row) => row.name"
        :preview-actions="previewActions"
        :create-record="createRecord"
    />
</template>
