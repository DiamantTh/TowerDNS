import type { Decorator, Preview } from '@storybook/svelte-vite';
import DataTableExample from '../storybook/fixtures/DataTableExample.svelte';
import DnsRecordsPageExample from '../storybook/fixtures/DnsRecordsPageExample.svelte';
import FeatureExample from '../storybook/fixtures/FeatureExample.svelte';
import '../themes/default/src/styles/app.css';

const withTowerEnvironment: Decorator = (storyFn, context) => {
    const root = document.documentElement;
    root.dataset.theme = String(context.globals.towerTheme ?? 'cerberus');
    root.lang = String(context.globals.towerLanguage ?? 'en-GB');
    root.classList.toggle('dark', context.globals.colorMode === 'dark');

    const story = storyFn();
    if (story.Component === DataTableExample || story.Component === FeatureExample || story.Component === DnsRecordsPageExample) {
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
        colorMode: 'light',
        towerLanguage: 'en-GB',
        viewport: { value: 'towerDesktop', isRotated: false },
    },
    parameters: {
        layout: 'padded',
        viewport: {
            options: {
                towerPhone: { name: 'TowerDNS phone · 390px', styles: { width: '390px', height: '844px' }, type: 'mobile' },
                towerTablet: { name: 'TowerDNS tablet · 768px', styles: { width: '768px', height: '1024px' }, type: 'tablet' },
                towerDesktop: { name: 'TowerDNS desktop · 1440px', styles: { width: '1440px', height: '900px' }, type: 'desktop' },
            },
        },
    },
    decorators: [withTowerEnvironment],
};

export default preview;
