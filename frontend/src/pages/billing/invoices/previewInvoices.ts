import { createPreviewRecords } from '@/lib/createPreviewRecords';

const northstar = {
    name: 'Northstar Gas Services',
    initials: 'NS',
    address: 'Dhaka, Bangladesh',
    email: 'billing@northstar.example',
};

type InvoiceLine = {
    charge:
        | 'invoice.gas_usage'
        | 'invoice.service_charge'
        | 'invoice.meter_charge';
    quantity: number;
    rate: number;
};

type PreviewInvoice = {
    number: string;
    issuer: typeof northstar;
    customer: string;
    building: string;
    flat: string;
    issuedOn: string | null;
    due: string;
    period?: string;
    paid: number;
    // invoices statuses in status_definitions.
    status: 'draft' | 'issued' | 'partially_paid' | 'paid' | 'overdue';
    lines: readonly InvoiceLine[];
};

function charges(usage: number, serviceCharge: number): InvoiceLine[] {
    return [
        { charge: 'invoice.gas_usage', quantity: usage, rate: 50 },
        { charge: 'invoice.service_charge', quantity: 1, rate: serviceCharge },
        { charge: 'invoice.meter_charge', quantity: 1, rate: 100 },
    ];
}

const additionalCustomers = [
    {
        name: 'Farzana Islam',
        building: 'Lake View Tower',
        flat: 'A-2A',
        usage: 17,
    },
    {
        name: 'Kamal Hossain',
        building: 'Green Residency',
        flat: 'B-5A',
        usage: 38,
    },
    {
        name: 'Samira Ahmed',
        building: 'Lake View Tower',
        flat: 'A-3B',
        usage: 13,
    },
    { name: 'Arif Khan', building: 'Green Residency', flat: 'B-2C', usage: 42 },
    {
        name: 'Nabila Rahman',
        building: 'Lake View Tower',
        flat: 'A-6C',
        usage: 28,
    },
    {
        name: 'Tariq Hasan',
        building: 'Green Residency',
        flat: 'B-3B',
        usage: 31,
    },
    {
        name: 'Sultana Akter',
        building: 'Lake View Tower',
        flat: 'A-5A',
        usage: 9,
    },
    {
        name: 'Shahid Ali',
        building: 'Green Residency',
        flat: 'B-1A',
        usage: 36,
    },
];

export const {
    rows: previewInvoices,
    add: insertPreviewInvoice,
    update: updatePreviewInvoice,
    remove: removePreviewInvoice,
} = createPreviewRecords<PreviewInvoice>(
    [
        {
            number: 'INV-2026-1042',
            issuer: northstar,
            customer: 'Nusrat Jahan',
            building: 'Lake View Tower',
            flat: 'A-4B',
            issuedOn: '2026-11-05',
            due: '2026-11-20',
            paid: 0,
            status: 'issued',
            lines: charges(42, 250),
        },
        {
            number: 'INV-2026-1041',
            issuer: northstar,
            customer: 'Rahim Ahmed',
            building: 'Green Residency',
            flat: 'B-3A',
            issuedOn: '2026-11-05',
            due: '2026-11-20',
            paid: 1500,
            status: 'partially_paid',
            lines: charges(56, 280),
        },
        {
            number: 'INV-2026-1040',
            issuer: northstar,
            customer: 'Imran Kabir',
            building: 'Lake View Tower',
            flat: 'A-4C',
            issuedOn: '2026-11-01',
            due: '2026-11-15',
            paid: 0,
            status: 'overdue',
            lines: charges(36, 220),
        },
        {
            number: 'INV-2026-1039',
            issuer: northstar,
            customer: 'Mitu Akter',
            building: 'Green Residency',
            flat: 'C-2A',
            issuedOn: '2026-11-01',
            due: '2026-11-15',
            paid: 1980,
            status: 'paid',
            lines: charges(34, 180),
        },
        ...additionalCustomers.map((customer, index): PreviewInvoice => {
            const lines = charges(customer.usage, 150);
            return {
                number: 'INV-2026-' + (1038 - index),
                issuer: northstar,
                customer: customer.name,
                building: customer.building,
                flat: customer.flat,
                issuedOn: '2026-11-01',
                due: '2026-11-10',
                paid: lines.reduce(
                    (total, line) => total + lineAmount(line),
                    0,
                ),
                status: 'paid',
                lines,
            };
        }),
    ],
    (invoice) => invoice.number,
);

let invoiceSequence = Math.max(
    ...previewInvoices.value.map((invoice) =>
        Number(invoice.number.split('-').at(-1)),
    ),
);

export function addPreviewInvoice(record: {
    customer: string;
    building: string;
    flat: string;
    period: string;
    due: string;
    amount: number;
}): void {
    const { amount, ...fields } = record;
    insertPreviewInvoice({
        ...fields,
        number: 'INV-' + record.period.slice(0, 4) + '-' + ++invoiceSequence,
        issuer: northstar,
        issuedOn: null,
        paid: 0,
        status: 'draft',
        lines: [
            { charge: 'invoice.service_charge', quantity: 1, rate: amount },
        ],
    });
}

export function lineAmount(line: InvoiceLine): number {
    return line.quantity * line.rate;
}

export function invoiceTotal(invoice: PreviewInvoice): number {
    return invoice.lines.reduce((total, line) => total + lineAmount(line), 0);
}
