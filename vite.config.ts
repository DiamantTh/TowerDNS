// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

import { defineConfig } from 'vite'
import { svelte } from '@sveltejs/vite-plugin-svelte'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
    base: '/assets/',
    plugins: [tailwindcss(), svelte()],
    build: {
        outDir: 'build/frontend-staging',
        emptyOutDir: true,
        manifest: true,
        assetsDir: '',
        rollupOptions: {
            input: {
                app: 'themes/default/src/app/main.ts',
                'theme-init': 'themes/default/src/app/theme-init.ts',
            },
            output: {
                entryFileNames: '[name]-[hash].js',
                chunkFileNames: '[name]-[hash].js',
                assetFileNames: '[name]-[hash][extname]',
                manualChunks(id) {
                    if (id.includes('/node_modules/svelte/') || id.includes('/themes/default/src/lib/') || /\/components\/(Field|Notice|Page|Strength)\.svelte/.test(id)) {
                        return 'shared'
                    }
                },
            },
        },
    },
})
