import type { Meta, StoryObj } from '@storybook/svelte-vite';
import FeatureExample from '../fixtures/FeatureExample.svelte';

const meta = {
    title: 'TowerDNS/Shared/Feature card',
    component: FeatureExample,
    args: {
        title: 'DNS zones',
        text: 'Review the zones available in the selected account.',
        href: '#dns-zones',
        language: 'en-GB',
    },
} satisfies Meta<typeof FeatureExample>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};

export const LongContent: Story = {
    args: {
        title: 'Provider account health and DNS synchronization',
        text: 'A longer description checks how the existing card handles real TowerDNS copy without introducing a replacement design.',
    },
};

export const German: Story = {
    args: { language: 'de-DE', title: 'DNS-Zonen', text: 'Zonen des ausgewählten Kontos anzeigen.' },
};
