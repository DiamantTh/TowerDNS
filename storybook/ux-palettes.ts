export type UxPaletteId = 'forest-light' | 'amber-light' | 'slate-hybrid' | 'oled-ocean-dark' | 'nordic-hybrid';

export type UxPalette = {
    id: UxPaletteId;
    code: '02' | '03' | '05' | '06' | 'N';
    name: string;
    mode: 'light' | 'dark' | 'hybrid';
    description: string;
    forceDark: boolean;
    colors: Record<'background' | 'navigation' | 'workspace' | 'panel' | 'text' | 'muted' | 'primary' | 'primaryHover' | 'focus' | 'border' | 'success' | 'warning' | 'danger' | 'info', string>;
};

export const uxPalettes: UxPalette[] = [
    {
        id: 'forest-light', code: '02', name: 'Forest Light', mode: 'light', forceDark: false,
        description: 'Tinted light work surfaces and a dark forest navigation bar.',
        colors: { background: '#edf5ee', navigation: '#153a2e', workspace: '#f7fbf7', panel: '#ffffff', text: '#16342a', muted: '#557468', primary: '#2f7a55', primaryHover: '#226143', focus: '#49a674', border: '#c7dbcf', success: '#2f8c62', warning: '#b8791d', danger: '#bd3d3d', info: '#2d6f8d' },
    },
    {
        id: 'amber-light', code: '03', name: 'Amber Light', mode: 'light', forceDark: false,
        description: 'Warm sand surfaces, copper actions, and a deliberate red danger accent.',
        colors: { background: '#f6efe3', navigation: '#362819', workspace: '#fffaf2', panel: '#fffdf8', text: '#33271d', muted: '#756553', primary: '#a66318', primaryHover: '#7e4910', focus: '#d08a2e', border: '#e2cfb5', success: '#397557', warning: '#bc780d', danger: '#b5343b', info: '#386e8e' },
    },
    {
        id: 'slate-hybrid', code: '05', name: 'Slate Hybrid', mode: 'hybrid', forceDark: false,
        description: 'Blue-slate navigation with medium-light, high-density work surfaces.',
        colors: { background: '#e9eff2', navigation: '#1f2d39', workspace: '#f4f7f8', panel: '#fbfcfc', text: '#1b2a35', muted: '#5a6a75', primary: '#1f6d88', primaryHover: '#18566d', focus: '#4d9fb8', border: '#c5d0d7', success: '#2b7f68', warning: '#aa741e', danger: '#b33a44', info: '#316f9c' },
    },
    {
        id: 'oled-ocean-dark', code: '06', name: 'OLED Ocean Dark', mode: 'dark', forceDark: true,
        description: 'Layered ocean-blue panels for OLED displays; deliberately not pure black.',
        colors: { background: '#07141c', navigation: '#081018', workspace: '#0a1b26', panel: '#102735', text: '#e4f1f5', muted: '#9bb5bd', primary: '#20a6c7', primaryHover: '#157e9a', focus: '#67d8ef', border: '#214454', success: '#31b484', warning: '#d69b38', danger: '#e05c66', info: '#4ba9e8' },
    },
    {
        id: 'nordic-hybrid', code: 'N', name: 'Nordic Hybrid', mode: 'hybrid', forceDark: false,
        description: 'Cool blue-grey work surfaces, dark navigation, and restrained marine accents.',
        colors: { background: '#e7eef3', navigation: '#172b3b', workspace: '#f6f9fb', panel: '#ffffff', text: '#152836', muted: '#597080', primary: '#337d9c', primaryHover: '#265f78', focus: '#67acc7', border: '#c7d5de', success: '#347b63', warning: '#ad771f', danger: '#b4434b', info: '#347ca6' },
    },
];

export const uxPaletteById = Object.fromEntries(uxPalettes.map((palette) => [palette.id, palette])) as Record<UxPaletteId, UxPalette>;
