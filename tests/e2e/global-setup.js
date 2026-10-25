/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { chromium } from '@playwright/test'
import { mkdirSync } from 'node:fs'
import { prepareInstance, USERS } from './helpers.js'

// Prepare the test users and sample recording, then log each test account in once and keep its session
// for the tests (build/e2e-auth/<user>.json).
export default async function globalSetup(config) {
	const baseURL = config.projects[0].use.baseURL
	mkdirSync('build/e2e-auth', { recursive: true })
	for (const [user, password] of Object.entries(USERS)) {
		if (!password) {
			throw new Error(`No password for ${user}: set NC_ADMIN_PASSWORD, TEST_USER_PASSWORD and TEST_USER2_PASSWORD`)
		}
	}
	await prepareInstance()
	const browser = await chromium.launch()
	for (const [user, password] of Object.entries(USERS)) {
		const page = await browser.newPage({ baseURL })
		await page.goto('/login')
		await page.locator('#user').fill(user)
		await page.locator('#password').fill(password)
		await page.locator('form button[type="submit"]').click()
		await page.waitForURL((url) => !url.pathname.startsWith('/login'))
		await page.context().storageState({ path: `build/e2e-auth/${user}.json` })
		await page.close()
	}
	await browser.close()
}
