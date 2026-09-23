import type { Meta, StoryObj } from '@storybook/svelte-vite';
import DataTableExample from '../fixtures/DataTableExample.svelte';

const meta = {
    title: 'TowerDNS/Shared/DataTable',
    component: DataTableExample,
    args: {
        rows: [
            { owner: 'example.test', type: 'A', ttl: 300, rdata: '203.0.113.42 · 203.0.113.43' },
            { owner: 'mail.example.test', type: 'AAAA', ttl: 900, rdata: '2001:db8::25' },
        ],
        cols: ['owner', 'type', 'ttl', 'rdata'],
        labels: ['Owner', 'Type', 'TTL', 'RDATA'],
        csrf: 'storybook-fixture-only-not-a-valid-token',
        action: (row: Record<string, unknown>) => `/storybook/fixture/rrsets/${encodeURIComponent(String(row.owner))}/delete`,
        actionText: 'Delete RRset',
        language: 'en-GB',
    },
} satisfies Meta<typeof DataTableExample>;

export default meta;
type Story = StoryObj<typeof meta>;

export const DNSRecordValues: Story = {};

export const ManyRows: Story = {
    args: {
        rows: Array.from({ length: 30 }, (_, index) => ({
            owner: index === 0 ? `${'long-subdomain.'.repeat(5)}example.test` : `host-${String(index + 1).padStart(2, '0')}.example.test`,
            type: index % 3 === 0 ? 'TXT' : index % 3 === 1 ? 'AAAA' : 'A',
            ttl: 300 + index * 60,
            rdata: index % 3 === 0 ? `v=spf1 include:mail-${index}.example.test ~all ${'synthetic-long-value '.repeat(5)}` : index % 3 === 1 ? '2001:db8:1200:beef:0000:0000:0000:0042' : `203.0.113.${index + 1}`,
        })),
    },
};

export const Empty: Story = {
    args: { rows: [], action: null },
};

/** The optional action prop represents UI visibility only; it is not authorization. */
export const ReadOnly: Story = {
    args: { action: null },
};

export const GermanLabels: Story = {
    args: {
        labels: ['Owner-Name', 'Typ', 'TTL', 'RDATA'],
        actionText: 'RRset löschen',
        language: 'de-DE',
    },
};
