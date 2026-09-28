import type { Meta, StoryObj } from '@storybook/svelte-vite';
import DataTableExample from '../fixtures/DataTableExample.svelte';
import { fixtureCsrfToken, fixtureManyRrsets, fixtureRrsets } from '../fixtures/towerdns-installation';

const rows = fixtureRrsets.map((rrset) => ({
    owner: rrset.ownerName,
    type: rrset.type.presentation,
    ttl: rrset.ttl,
    rdata: rrset.rdata.join(' · '),
}));

const meta = {
    title: 'TowerDNS/Shared/DataTable',
    component: DataTableExample,
    args: {
        rows,
        cols: ['owner', 'type', 'ttl', 'rdata'],
        labels: ['Owner', 'Type', 'TTL', 'RDATA'],
        csrf: fixtureCsrfToken,
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
        rows: fixtureManyRrsets.map((rrset) => ({ owner: rrset.ownerName, type: rrset.type.presentation, ttl: rrset.ttl, rdata: rrset.rdata.join(' · ') })),
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
