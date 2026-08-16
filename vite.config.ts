// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

import { defineConfig } from 'vite'
import { svelte } from '@sveltejs/vite-plugin-svelte'
import tailwindcss from '@tailwindcss/vite'

// Svelte renders the complete application UI from the server bootstrap.
export default defineConfig({
    plugins: [tailwindcss(), svelte()],
    build: {
        outDir: 'httpdocs/assets',
        emptyOutDir: true,
        rollupOptions: {
            input: {
                app: 'themes/default/src/app/main.ts',
                'theme-init': 'themes/default/src/app/theme-init.ts',
            },
            output: {
                entryFileNames: '[name].bundle.js',
                chunkFileNames: '[name]-[hash].chunk.js',
                assetFileNames: '[name][extname]',
            },
        },
    },
})
