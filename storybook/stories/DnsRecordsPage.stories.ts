import type { Meta, StoryObj } from '@storybook/svelte-vite';
import DnsRecordsPageExample from '../fixtures/DnsRecordsPageExample.svelte';
import { createDnsRecordsPage, longTxtRrsets } from '../fixtures/dns-records';

const meta = {
    title: 'TowerDNS/DNS/Record sets page',
    component: DnsRecordsPageExample,
    parameters: { layout: 'fullscreen' },
    args: { page: createDnsRecordsPage(), language: 'en-GB' },
} satisfies Meta<typeof DnsRecordsPageExample>;

export default meta;
type Story = StoryObj<typeof meta>;

export const MultipleRrsets: Story = {};

export const EmptyZone: Story = {
    args: { page: createDnsRecordsPage({ rrsets: [] }) },
};

export const ProviderError: Story = {
    args: {
        page: createDnsRecordsPage({
            error: 'The synthetic provider did not respond. No live provider is contacted.',
            canReplaceRrsets: false,
            canDeleteRrsets: false,
            rrsets: [],
        }),
    },
};

export const LongTxtValues: Story = {
    args: { page: createDnsRecordsPage({ rrsets: longTxtRrsets }) },
};

/** Server-derived availability is displayed here; this fixture is not authorization. */
export const MissingWritePermission: Story = {
    args: {
        page: createDnsRecordsPage({ canReplaceRrsets: false, canDeleteRrsets: false }),
    },
    parameters: { docs: { description: { story: 'The write form fields and delete buttons are disabled. Real authorization remains enforced by the Mezzio handlers and application service.' } } },
};

/** Demonstrates a provider that allows replace but does not advertise RRset deletion. */
export const RestrictedProviderCapabilities: Story = {
    args: {
        page: createDnsRecordsPage({ canReplaceRrsets: true, canDeleteRrsets: false }),
    },
};

export const German: Story = {
    args: { language: 'de-DE' },
};

export const PhoneViewport: Story = {
    globals: { viewport: { value: 'towerPhone', isRotated: false } },
};

export const TabletViewport: Story = {
    globals: { viewport: { value: 'towerTablet', isRotated: false } },
};

export const DesktopViewport: Story = {
    globals: { viewport: { value: 'towerDesktop', isRotated: false } },
};
