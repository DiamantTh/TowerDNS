import type { Meta, StoryObj } from '@storybook/svelte-vite';
import AppShellExample from '../fixtures/AppShellExample.svelte';

const meta = {
    title: 'TowerDNS/Layout/App shell',
    component: AppShellExample,
    parameters: { layout: 'fullscreen' },
    args: { language: 'en-GB', dark: false, accountLabel: 'Example Hosting' },
} satisfies Meta<typeof AppShellExample>;

export default meta;
type Story = StoryObj<typeof meta>;

export const OrganizationContext: Story = {};

export const PersonalContext: Story = {
    args: { accountLabel: 'Taylor Example · Personal account' },
};

export const LongOrganizationName: Story = {
    args: { accountLabel: 'Example Hosting and Managed DNS Services for Northern Europe Ltd.' },
};

export const German: Story = {
    args: { language: 'de-DE' },
};

export const LimitedNavigation: Story = {
    args: { permissionSet: [] },
    parameters: { docs: { description: { story: 'Navigation is derived from the supplied role permissions; hiding links never replaces server-side authorization.' } } },
};

export const DarkMode: Story = {
    args: { dark: true },
    globals: { colorMode: 'dark' },
};

export const PhoneViewport: Story = {
    globals: { viewport: { value: 'towerPhone', isRotated: false } },
};
