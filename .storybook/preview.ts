import type { Decorator, Preview } from '@storybook/svelte-vite';
import DataTableExample from '../storybook/fixtures/DataTableExample.svelte';
import DnsRecordsPageExample from '../storybook/fixtures/DnsRecordsPageExample.svelte';
import DnsZonesPageExample from '../storybook/fixtures/DnsZonesPageExample.svelte';
import FeatureExample from '../storybook/fixtures/FeatureExample.svelte';
import AppShellExample from '../storybook/fixtures/AppShellExample.svelte';
import ApplicationPageExample from '../storybook/fixtures/ApplicationPageExample.svelte';
import '../themes/default/src/styles/app.css';
import '../storybook/ux-palettes.css';
import { uxPaletteById, uxPalettes, type UxPaletteId } from '../storybook/ux-palettes';
import { installStorybookPreviewGuards } from '../storybook/preview-navigation';

installStorybookPreviewGuards();

const withTowerEnvironment: Decorator = (storyFn, context) => {
    const root = document.documentElement;
    const paletteId = String(context.globals.towerPalette ?? 'slate-hybrid') as UxPaletteId;
    const palette = uxPaletteById[paletteId] ?? uxPaletteById['slate-hybrid'];
    root.dataset.theme = String(context.globals.towerTheme ?? 'cerberus');
    root.dataset.towerPalette = palette.id;
    root.lang = String(context.globals.towerLanguage ?? 'en-GB');
    root.classList.toggle('dark', palette.forceDark || context.globals.colorMode === 'dark');

    const story = storyFn();
    if (story.Component === DataTableExample || story.Component === FeatureExample || story.Component === DnsRecordsPageExample || story.Component === DnsZonesPageExample || story.Component === AppShellExample || story.Component === ApplicationPageExample) {
        return {
            ...story,
            props: { ...story.props, language: String(context.globals.towerLanguage ?? 'en-GB') },
        };
    }

    return story;
};

const preview: Preview = {
    globalTypes: {
        towerTheme: {
            description: 'Skeleton theme used by TowerDNS',
            toolbar: {
                title: 'Theme',
                icon: 'paintbrush',
                items: [
                    { value: 'cerberus', title: 'Cerberus' },
                    { value: 'modern', title: 'Modern' },
                    { value: 'terminus', title: 'Terminus' },
                ],
                dynamicTitle: true,
            },
        },
        towerPalette: {
            description: 'TowerDNS UX palette preview; Storybook-only token overrides',
            toolbar: {
                title: 'UX palette',
                icon: 'paintbrush',
                items: uxPalettes.map((palette) => ({ value: palette.id, title: `${palette.code} · ${palette.name}` })),
                dynamicTitle: true,
            },
        },
        colorMode: {
            description: 'TowerDNS light or dark mode',
            toolbar: {
                title: 'Color mode',
                icon: 'circlehollow',
                items: [
                    { value: 'light', title: 'Light' },
                    { value: 'dark', title: 'Dark' },
                ],
                dynamicTitle: true,
            },
        },
        towerLanguage: {
            description: 'Fixture language for context-based components',
            toolbar: {
                title: 'Language',
                icon: 'globe',
                items: [
                    { value: 'en-GB', title: 'English (UK)' },
                    { value: 'de-DE', title: 'Deutsch' },
                ],
                dynamicTitle: true,
            },
        },
    },
    initialGlobals: {
        towerTheme: 'cerberus',
        towerPalette: 'slate-hybrid',
        colorMode: 'light',
        towerLanguage: 'en-GB',
        viewport: { value: 'towerDesktop', isRotated: false },
    },
    parameters: {
        layout: 'padded',
        viewport: {
            options: {
                tower320: { name: 'TowerDNS · 320px', styles: { width: '320px', height: '844px' }, type: 'mobile' },
                tower360: { name: 'TowerDNS · 360px', styles: { width: '360px', height: '844px' }, type: 'mobile' },
                towerPhone: { name: 'TowerDNS phone · 390px', styles: { width: '390px', height: '844px' }, type: 'mobile' },
                tower430: { name: 'TowerDNS · 430px', styles: { width: '430px', height: '900px' }, type: 'mobile' },
                tower600: { name: 'TowerDNS · 600px', styles: { width: '600px', height: '900px' }, type: 'mobile' },
                towerZoom200: { name: 'TowerDNS · 640px (1280px at 200% zoom)', styles: { width: '640px', height: '900px' }, type: 'tablet' },
                towerTablet: { name: 'TowerDNS tablet · 768px', styles: { width: '768px', height: '1024px' }, type: 'tablet' },
                tower800: { name: 'TowerDNS · 800px', styles: { width: '800px', height: '1000px' }, type: 'tablet' },
                tower801: { name: 'TowerDNS · 801px', styles: { width: '801px', height: '1000px' }, type: 'tablet' },
                tower900: { name: 'TowerDNS · 900px', styles: { width: '900px', height: '1000px' }, type: 'tablet' },
                tower1024: { name: 'TowerDNS · 1024px', styles: { width: '1024px', height: '1000px' }, type: 'tablet' },
                tower1280: { name: 'TowerDNS · 1280px', styles: { width: '1280px', height: '1000px' }, type: 'desktop' },
                towerDesktop: { name: 'TowerDNS desktop · 1440px', styles: { width: '1440px', height: '900px' }, type: 'desktop' },
                tower1920: { name: 'TowerDNS · 1920px', styles: { width: '1920px', height: '1080px' }, type: 'desktop' },
                tower2560: { name: 'TowerDNS ultrawide · 2560px', styles: { width: '2560px', height: '1440px' }, type: 'desktop' },
            },
        },
    },
    decorators: [withTowerEnvironment],
};

export default preview;
