/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineConfig, devices } from '@playwright/test'

// End-to-end tests against a running Nextcloud (NC_URL). `make test-e2e` runs them in the official
// Playwright container with the test accounts' passwords from the environment; see docs/development.md.
// The tests share the dev instance's data, so they run one at a time.
export default defineConfig({
	testDir: 'tests/e2e',
	globalSetup: './tests/e2e/global-setup.js',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	timeout: 60_000,
	expect: { timeout: 15_000 },
	reporter: [['list'], ['html', { open: 'never', outputFolder: 'build/playwright-report' }]],
	outputDir: 'build/test-results',
	use: {
		baseURL: process.env.NC_URL ?? 'http://localhost:8090',
		storageState: 'build/e2e-auth/tester.json',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		// A fake microphone (a steady tone) that needs no permission prompt, for the voice-note tests.
		permissions: ['microphone'],
		launchOptions: {
			args: ['--autoplay-policy=no-user-gesture-required', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'],
		},
	},
	projects: [
		{ name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
		{ name: 'phone', use: { ...devices['Pixel 7'] } },
	],
})
