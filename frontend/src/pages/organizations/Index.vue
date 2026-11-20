<script setup lang="ts">
import { computed, watchEffect } from 'vue';
import ModuleListing from '@/components/app/modules/ModuleListing.vue';
import { useLocale } from '@/composables/useLocale';
import { formatNumber } from '@/lib/format';

const { locale, t } = useLocale();
watchEffect(() => {
    document.title = t('navigation.organizations') + ' - GasFlow';
});

const organizations = [
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
] as const;

const rows = computed(() =>
    organizations.map((row) => ({
        cells: [
            row.name,
            t(row.type),
            row.contact,
            formatNumber(row.buildings, locale.value),
            formatNumber(row.members, locale.value),
        ],
        statusCode: row.status,
        status: t(row.status),
        tone: row.tone,
    })),
);
</script>

<template>
    <ModuleListing
        :title="t('navigation.organizations')"
        :description="t('module.organizations_description')"
        :columns="[
            t('module.organization'),
            t('module.type'),
            t('module.contact'),
            t('dashboard.buildings'),
            t('module.members'),
        ]"
        :rows="rows"
    />
</template>
