import type { Meta, StoryObj } from '@storybook/svelte-vite';
import Notice from '../../themes/default/src/components/Notice.svelte';

const meta = {
    title: 'TowerDNS/Shared/Notice',
    component: Notice,
    args: { kind: 'info', text: 'Provider synchronization completed.' },
    argTypes: {
        kind: { control: 'select', options: ['info', 'success', 'warning', 'danger'] },
    },
} satisfies Meta<typeof Notice>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Information: Story = {};

export const Error: Story = {
    args: { kind: 'danger', text: 'The provider could not return the current zone data.' },
};

export const Warning: Story = {
    args: { kind: 'warning', text: 'The displayed provider data may be out of date.' },
};
