export const navigation = [
    {
        label: 'navigation.platform',
        items: [
            'navigation.organizations',
            'navigation.plans',
            'navigation.users',
        ],
    },
    {
        label: 'navigation.operations',
        items: [
            'navigation.buildings',
            'navigation.readings',
            'navigation.reconciliation',
        ],
    },
    {
        label: 'navigation.billing',
        items: [
            'navigation.invoices',
            'navigation.payments',
            'navigation.ledger',
        ],
    },
] as const;
