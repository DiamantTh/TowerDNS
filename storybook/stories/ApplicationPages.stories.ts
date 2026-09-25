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
export const Accounts: Story = { args: { view: 'accounts' } };
export const Users: Story = { args: { view: 'users' } };
export const Roles: Story = { args: { view: 'roles' } };
export const Profile: Story = { args: { view: 'profile' } };
export const SystemSettings: Story = { args: { view: 'settings' } };
export const Login: Story = { args: { view: 'login' } };
export const GermanDashboard: Story = { globals: { towerLanguage: 'de-DE' } };
export const PhoneProviders: Story = { args: { view: 'providerAccounts' }, globals: { viewport: { value: 'towerPhone', isRotated: false } } };
export const PhoneUsers: Story = { args: { view: 'users' }, globals: { viewport: { value: 'towerPhone', isRotated: false } } };
export const PhoneProfile: Story = { args: { view: 'profile' }, globals: { viewport: { value: 'towerPhone', isRotated: false } } };
export const PhoneSystemSettings: Story = { args: { view: 'settings' }, globals: { viewport: { value: 'towerPhone', isRotated: false } } };
export const PhoneLogin: Story = { args: { view: 'login' }, globals: { viewport: { value: 'towerPhone', isRotated: false } } };
