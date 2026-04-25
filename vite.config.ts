// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

import { defineConfig } from 'vite'
import { svelte } from '@sveltejs/vite-plugin-svelte'

// Jede Svelte-App (Seiten-Bereich) ist ein eigener Entry-Point.
// Output: themes/default/js/<name>.bundle.js — von PHP-Templates eingebunden.
export default defineConfig({
    plugins: [svelte()],
    build: {
        outDir: 'httpdocs/assets',
        emptyOutDir: false,
        rollupOptions: {
            input: {
                records: 'themes/default/src/records/main.ts',
                pwtools: 'themes/default/src/pwtools/main.ts',
                // weitere Bereiche bei Bedarf
            },
            output: {
                entryFileNames: '[name].bundle.js',
                chunkFileNames: '[name].chunk.js',
                assetFileNames: '[name][extname]',
            },
        },
    },
})
