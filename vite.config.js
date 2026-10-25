/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import vue from '@vitejs/plugin-vue'
import { readFileSync } from 'node:fs'
import { defineConfig } from 'vite'

// One ES module per entry, loaded by Util::addScript (it prefers .mjs); shared code goes to js/chunks/.
// All CSS lands in one file, moved to css/audioannotations.css by the build script.
export default defineConfig({
	plugins: [vue()],
	define: {
		'process.env.NODE_ENV': JSON.stringify('production'),
		appName: JSON.stringify('audioannotations'),
		appVersion: JSON.stringify(JSON.parse(readFileSync('package.json', 'utf8')).version),
	},
	build: {
		outDir: 'js',
		emptyOutDir: true,
		copyPublicDir: false,
		sourcemap: true,
		cssCodeSplit: false,
		lib: {
			entry: {
				'audioannotations-sidebar': 'src/sidebar.js',
				'audioannotations-viewer': 'src/viewer.js',
			},
			formats: ['es'],
			fileName: (format, name) => `${name}.mjs`,
		},
		rollupOptions: {
			output: { chunkFileNames: 'chunks/[name]-[hash].mjs' },
		},
	},
})
