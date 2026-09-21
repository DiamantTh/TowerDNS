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
