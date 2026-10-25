/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { fileId, openRecording, RECORDING_PATH } from './helpers.js'

// Plan case 23 (part): the player fits the screen in both projects, in light and dark theme.
let id

test.beforeAll(async ({ request }) => {
	id = await fileId(request, 'tester', RECORDING_PATH)
})

for (const colorScheme of ['light', 'dark']) {
	test(`fits the screen without sideways scrolling (${colorScheme})`, async ({ page }) => {
		await page.emulateMedia({ colorScheme })
		await openRecording(page, test, id)
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
		expect(overflow).toBeLessThanOrEqual(0)
		await expect(page.locator('.anp__dock')).toBeInViewport()
		await expect(page.locator('.anp__wave')).toBeInViewport()
		const zoom = page.locator('.anp__zoom')
		if (test.info().project.name === 'phone') {
			await expect(zoom).toBeHidden()
		} else {
			await expect(zoom).toBeVisible()
		}
	})
}
