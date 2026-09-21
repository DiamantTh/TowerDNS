import type { Meta, StoryObj } from '@storybook/svelte-vite';
import DnsZonesPageExample from '../fixtures/DnsZonesPageExample.svelte';
import { exampleDnsZonesPage } from '../fixtures/dns-zones';

const meta = {
    title: 'TowerDNS/DNS/Zone list',
    component: DnsZonesPageExample,
    parameters: { layout: 'fullscreen' },
    args: { page: exampleDnsZonesPage, language: 'en-GB' },
} satisfies Meta<typeof DnsZonesPageExample>;

export default meta;
type Story = StoryObj<typeof meta>;

export const ProviderAndZones: Story = {};

export const EmptyAccount: Story = {
    args: { page: { ...exampleDnsZonesPage, providerAccounts: [], providerNames: {}, managedZones: [] } },
};

export const German: Story = { args: { language: 'de-DE' } };

export const PhoneViewport: Story = {
    globals: { viewport: { value: 'towerPhone', isRotated: false } },
};
