/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { cleanup, clickOnWave, createNote, dragOnWave, fileId, openRecording, playButton, RECORDING, RECORDING_PATH, selectedRange, timeText } from './helpers.js'

// Plan cases 1, 3, 5, 6, 7 and 8 on the 5:49 test recording.
let id

test.beforeAll(async ({ request }) => {
	id = await fileId(request, 'tester', RECORDING_PATH)
})

test.afterEach(async ({ request }) => {
	await cleanup(request, id)
})

test('opens the notes player instead of the stock audio player', async ({ page }, info) => {
	await openRecording(page, test, id)
	// The only media elements in the Viewer should be the voice-note players inside the notes player.
	const foreign = await page.evaluate(() => [...document.querySelectorAll('.viewer audio, .viewer video')]
		.filter((el) => !el.closest('.anp'))
		.map((el) => `${el.tagName} ${el.className} ${el.currentSrc || el.getAttribute('src') || ''}`))
	expect(foreign).toEqual([])
	await expect(page.locator('.anp__dock input')).toBeVisible()
	info.annotations.push({ type: 'project', description: info.project.name })
})

test('dragging across the waveform selects a range and stays on the recording', async ({ page }) => {
	await openRecording(page, test, id)
	await dragOnWave(page, 0.6, 0.75)
	const range = await selectedRange(page)
	expect(range).not.toBeNull()
	expect(range[0]).toMatch(/^3:/)
	expect(range[1]).toMatch(/^4:/)
	await expect(page).toHaveTitle(new RegExp(RECORDING.replace('.', '\\.')))
})

test('a tap outside the range goes to its start and stays paused', async ({ page }) => {
	await openRecording(page, test, id)
	await dragOnWave(page, 0.6, 0.75)
	const [start] = await selectedRange(page)
	await clickOnWave(page, 0.2)
	await expect.poll(() => timeText(page)).toBe(start)
	await expect(playButton(page)).toHaveAttribute('aria-label', 'Play')
})

test('Play from outside the range starts at the range start', async ({ page }) => {
	await openRecording(page, test, id)
	await dragOnWave(page, 0.6, 0.75)
	const [start] = await selectedRange(page)
	// Move the playhead out of the range with the keyboard (taps snap back to the start by design).
	await page.locator('.anp').focus()
	await page.keyboard.press('ArrowLeft')
	await page.keyboard.press('ArrowLeft')
	await expect.poll(() => timeText(page)).not.toBe(start)
	await playButton(page).click()
	await expect(playButton(page)).toHaveAttribute('aria-label', 'Pause')
	expect(await timeText(page)).toBe(start)
	await playButton(page).click()
})

test('with Loop off, playback stops at the end of the range; Play there starts it again', async ({ page, request }) => {
	await createNote(request, 'tester', id, { startMs: 60000, endMs: 63000, body: 'loop range' })
	await openRecording(page, test, id)
	await page.locator('.anp__notes summary').click()
	await page.locator('.anp__note', { hasText: 'loop range' }).click()
	const loop = page.locator('.anp__transport-side--end input')
	if (await loop.isChecked()) {
		await page.locator('.anp__transport-side--end label, .anp__transport-side--end .checkbox-radio-switch').first().click()
	}
	await expect(loop).not.toBeChecked()
	await playButton(page).click()
	await expect(playButton(page)).toHaveAttribute('aria-label', 'Pause')
	await expect(playButton(page)).toHaveAttribute('aria-label', 'Play', { timeout: 10_000 })
	expect(await timeText(page)).toBe('1:03')
	await playButton(page).click()
	await expect.poll(() => timeText(page)).toBe('1:00')
	await playButton(page).click()
})

test('a new range shows a clear button; a saved note\'s range does not', async ({ page, request }) => {
	await createNote(request, 'tester', id, { startMs: 90000, endMs: 95000, body: 'saved range' })
	await openRecording(page, test, id)
	const clear = page.locator('button[aria-label="Clear selection"]')
	await page.locator('.anp__notes summary').click()
	await page.locator('.anp__note', { hasText: 'saved range' }).click()
	await expect(page.locator('.anp__dock-target')).toContainText('1:30.0')
	await expect(clear).toHaveCount(0)
	await dragOnWave(page, 0.7, 0.8)
	await expect(clear).toBeVisible()
	const before = await timeText(page)
	await clear.click()
	await expect(clear).toHaveCount(0)
	await expect(page.locator('.anp__dock-target')).not.toContainText('→')
	expect(await timeText(page)).toBe(before)
})
