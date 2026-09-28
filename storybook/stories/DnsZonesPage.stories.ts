import type { Meta, StoryObj } from '@storybook/svelte-vite';
import DnsZonesPageExample from '../fixtures/DnsZonesPageExample.svelte';
import { exampleDnsZonesPage } from '../fixtures/dns-zones';
import { fixtureManyManagedZones } from '../fixtures/towerdns-installation';

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

export const ManyZones: Story = {
    args: { page: { ...exampleDnsZonesPage, managedZones: fixtureManyManagedZones } },
};

export const ProviderError: Story = {
    args: { page: { ...exampleDnsZonesPage, error: 'Synthetic Cloudflare connection failed. No provider was contacted.' } },
};

export const LongValues: Story = {
    args: { page: { ...exampleDnsZonesPage, managedZones: exampleDnsZonesPage.managedZones.filter((zone) => zone.id === 845) } },
};

export const German: Story = { globals: { towerLanguage: 'de-DE' } };

export const PhoneViewport: Story = {
    globals: { viewport: { value: 'towerPhone', isRotated: false } },
};
