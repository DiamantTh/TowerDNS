import type { Meta, StoryObj } from '@storybook/svelte-vite';
import ApplicationPageExample from '../fixtures/ApplicationPageExample.svelte';

const meta = {
    title: 'TowerDNS/UX previews/Application pages',
    component: ApplicationPageExample,
    parameters: { layout: 'fullscreen' },
    args: { view: 'dashboard', language: 'en-GB' },
} satisfies Meta<typeof ApplicationPageExample>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Real App.svelte with a synthetic, secrets-free server bootstrap. */
export const Dashboard: Story = {};
export const ProviderAccounts: Story = { args: { view: 'providerAccounts' } };
export const AccountMembers: Story = { args: { view: 'accountMembers' } };
export const GermanDashboard: Story = { args: { language: 'de-DE' } };
export const PhoneProviders: Story = { args: { view: 'providerAccounts' }, globals: { viewport: { value: 'towerPhone', isRotated: false } } };
