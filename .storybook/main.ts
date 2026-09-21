import { fileURLToPath } from 'node:url';
import { mergeConfig } from 'vite';
import type { StorybookConfig } from '@storybook/svelte-vite';

const projectRoot = fileURLToPath(new URL('..', import.meta.url));

const config: StorybookConfig = {
    stories: ['../storybook/stories/**/*.stories.ts'],
    framework: {
        name: '@storybook/svelte-vite',
        options: {},
    },
    async viteFinal(viteConfig) {
        return mergeConfig(viteConfig, {
            root: projectRoot,
            // Keep Storybook's generated site separate from TowerDNS's public assets.
            build: {
                outDir: fileURLToPath(new URL('../storybook-static', import.meta.url)),
                emptyOutDir: true,
            },
            server: {
                fs: { allow: [projectRoot] },
            },
        });
    },
};

export default config;
