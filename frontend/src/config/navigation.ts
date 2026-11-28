import {
    Activity,
    ArrowLeftRight,
    BadgeCheck,
    Bell,
    BookOpen,
    Building,
    Building2,
    CalendarRange,
    ChartColumn,
    CreditCard,
    DoorOpen,
    FileChartColumn,
    FileText,
    Flag,
    Flame,
    Gauge,
    Globe,
    Hammer,
    HeartHandshake,
    History,
    KeyRound,
    Layers,
    LayoutDashboard,
    ListChecks,
    Package,
    Plug,
    ReceiptText,
    ScrollText,
    Settings,
    Tag,
    Users,
    Wallet,
    Workflow,
} from '@lucide/vue';

export const dashboardNavigationItem = {
    label: 'navigation.dashboard',
    icon: LayoutDashboard,
    route: 'platform-dashboard',
} as const;

export const navigation = [
    {
        label: 'navigation.platform',
        icon: Layers,
        items: [
            {
                label: 'navigation.organizations',
                icon: Building2,
                route: 'organizations',
            },
            { label: 'navigation.access', icon: KeyRound },
            { label: 'navigation.plans', icon: Package },
            { label: 'navigation.saas_invoices', icon: ReceiptText },
            { label: 'navigation.licensing', icon: BadgeCheck },
            { label: 'navigation.users', icon: Users },
        ],
    },
    {
        label: 'navigation.operations',
        icon: Workflow,
        items: [
            { label: 'navigation.setup', icon: ListChecks },
            { label: 'navigation.buildings', icon: Building },
            { label: 'navigation.ownerships', icon: ScrollText },
            { label: 'navigation.occupancies', icon: DoorOpen },
            { label: 'navigation.meters', icon: Gauge },
            { label: 'navigation.readings', icon: Activity },
            { label: 'navigation.reconciliation', icon: ArrowLeftRight },
        ],
    },
    {
        label: 'navigation.billing',
        icon: Wallet,
        items: [
            { label: 'navigation.supply', icon: Flame },
            { label: 'navigation.tariffs', icon: Tag },
            {
                label: 'navigation.cycles',
                icon: CalendarRange,
                route: 'billing-cycles',
            },
            {
                label: 'navigation.invoices',
                icon: FileText,
                route: 'customer-invoices',
            },
            { label: 'navigation.payments', icon: CreditCard },
            { label: 'navigation.ledger', icon: BookOpen },
            { label: 'navigation.disputes', icon: Flag },
            { label: 'navigation.installations', icon: Hammer },
        ],
    },
    {
        label: 'navigation.customer_experience',
        icon: HeartHandshake,
        items: [
            { label: 'navigation.portal', icon: Globe },
            { label: 'navigation.notifications', icon: Bell },
        ],
    },
    {
        label: 'navigation.insights',
        icon: ChartColumn,
        items: [
            { label: 'navigation.reports', icon: FileChartColumn },
            { label: 'navigation.integrations', icon: Plug },
            { label: 'navigation.audit', icon: History },
            { label: 'navigation.settings', icon: Settings },
        ],
    },
] as const;

export interface SearchEntry {
    label:
        | (typeof navigation)[number]['items'][number]['label']
        | typeof dashboardNavigationItem.label
        | 'profile.title';
    route?: string;
    group?: (typeof navigation)[number]['label'];
}

export const searchableNavigation: readonly SearchEntry[] = [
    dashboardNavigationItem,
    ...navigation.flatMap((group) =>
        group.items.map((item) => ({ ...item, group: group.label })),
    ),
    { label: 'profile.title', route: 'account-profile' },
];
