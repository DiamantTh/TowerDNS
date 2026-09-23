import type { Meta, StoryObj } from '@storybook/svelte-vite';
import PaletteBoard from '../fixtures/PaletteBoard.svelte';
import { uxPaletteById } from '../ux-palettes';

const meta = {
    title: 'TowerDNS/UX previews/Palette tokens',
    component: PaletteBoard,
    parameters: { layout: 'fullscreen' },
    args: { palette: uxPaletteById['slate-hybrid'] },
} satisfies Meta<typeof PaletteBoard>;

export default meta;
type Story = StoryObj<typeof meta>;

export const ForestLight: Story = { args: { palette: uxPaletteById['forest-light'] }, globals: { towerPalette: 'forest-light' } };
export const AmberLight: Story = { args: { palette: uxPaletteById['amber-light'] }, globals: { towerPalette: 'amber-light' } };
export const SlateHybrid: Story = { args: { palette: uxPaletteById['slate-hybrid'] }, globals: { towerPalette: 'slate-hybrid' } };
export const OledOceanDark: Story = { args: { palette: uxPaletteById['oled-ocean-dark'] }, globals: { towerPalette: 'oled-ocean-dark', colorMode: 'dark' } };
export const NordicHybrid: Story = { args: { palette: uxPaletteById['nordic-hybrid'] }, globals: { towerPalette: 'nordic-hybrid' } };
