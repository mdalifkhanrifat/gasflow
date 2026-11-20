export const navigation = [
    {
        label: 'navigation.platform',
        items: [
            {
                label: 'navigation.organizations',
                icon: '▣',
                route: 'organizations',
            },
            { label: 'navigation.access', icon: '⌘' },
            { label: 'navigation.plans', icon: '◇' },
            { label: 'navigation.saas_invoices', icon: '৳' },
            { label: 'navigation.licensing', icon: '♢' },
            { label: 'navigation.users', icon: '♟' },
        ],
    },
    {
        label: 'navigation.operations',
        items: [
            { label: 'navigation.setup', icon: '⚙' },
            { label: 'navigation.buildings', icon: '⌂' },
            { label: 'navigation.ownerships', icon: '⌑' },
            { label: 'navigation.occupancies', icon: '♙' },
            { label: 'navigation.meters', icon: '◉' },
            { label: 'navigation.readings', icon: '⌁' },
            { label: 'navigation.reconciliation', icon: '⇄' },
        ],
    },
    {
        label: 'navigation.billing',
        items: [
            { label: 'navigation.supply', icon: '♨' },
            { label: 'navigation.tariffs', icon: '৳' },
            { label: 'navigation.cycles', icon: '▤' },
            {
                label: 'navigation.invoices',
                icon: '▧',
                route: 'customer-invoices',
            },
            { label: 'navigation.payments', icon: '⇆' },
            { label: 'navigation.ledger', icon: '≋' },
            { label: 'navigation.disputes', icon: '⚑' },
            { label: 'navigation.installations', icon: '⚒' },
        ],
    },
    {
        label: 'navigation.customer_experience',
        items: [
            { label: 'navigation.portal', icon: '♧' },
            { label: 'navigation.notifications', icon: '✉' },
        ],
    },
    {
        label: 'navigation.insights',
        items: [
            { label: 'navigation.reports', icon: '▥' },
            { label: 'navigation.integrations', icon: '⌘' },
            { label: 'navigation.audit', icon: '◷' },
            { label: 'navigation.settings', icon: '⚙' },
        ],
    },
] as const;
