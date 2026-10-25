/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { cleanup, createNote, dragOnWave, fileId, listNotes, MARK, openRecording, RECORDING_PATH } from './helpers.js'

// Plan cases 9, 10 and 13.
let id

test.beforeAll(async ({ request }) => {
	id = await fileId(request, 'tester', RECORDING_PATH)
})

test.afterEach(async ({ request }) => {
	await cleanup(request, id)
})

const bars = (page) => page.locator('.anp__lanes-track > *')

async function addFromDock(page, text) {
	await page.locator('.anp__dock input').fill(text)
	await page.locator('.anp__dock-btn--send').click()
}

test('a note on a selected range appears in the list and as a bar', async ({ page, request }) => {
	await openRecording(page, test, id)
	const barsBefore = await bars(page).count()
	await dragOnWave(page, 0.5, 0.55)
	await addFromDock(page, `${MARK} ranged note`)
	await expect(bars(page)).toHaveCount(barsBefore + 1)
	const { notes } = await listNotes(request, 'tester', id)
	const saved = notes.find((n) => n.body === `${MARK} ranged note`)
	expect(saved.general).toBe(false)
	expect(saved.endMs).toBeGreaterThan(saved.startMs)
})

test('a note written at 0:00 with nothing selected is a General Comment', async ({ page, request }) => {
	await openRecording(page, test, id)
	await expect(page.locator('.anp__dock-target')).toContainText('General Comment')
	const barsBefore = await bars(page).count()
	await addFromDock(page, `${MARK} overall impression`)
	await page.locator('.anp__notes summary').click()
	await expect(page.locator('.anp__note', { hasText: 'overall impression' })).toContainText('General')
	await expect(bars(page)).toHaveCount(barsBefore)
	const { notes } = await listNotes(request, 'tester', id)
	expect(notes.find((n) => n.body === `${MARK} overall impression`)).toMatchObject({ startMs: 0, endMs: 0, general: true })
})

test('the list starts collapsed and selecting a note shows only that note', async ({ page, request }) => {
	await createNote(request, 'tester', id, { startMs: 200000, endMs: 205000, body: 'pick me' })
	await createNote(request, 'tester', id, { startMs: 230000, endMs: 235000, body: 'not me' })
	await openRecording(page, test, id)
	const list = page.locator('details.anp__notes')
	await expect(list).not.toHaveAttribute('open', '')
	const total = (await listNotes(request, 'tester', id)).notes.length
	await list.locator('summary').click()
	await expect(list.locator(':scope > ul > li')).toHaveCount(total)
	await page.locator('.anp__note', { hasText: 'pick me' }).click()
	await expect(list.locator(':scope > ul > li')).toHaveCount(1)
	await expect(list.locator('summary')).toHaveText(`Show all ${total} notes`)
	await list.locator('summary').click()
	await expect(list.locator(':scope > ul > li')).toHaveCount(total)
	await expect(page.locator('.anp__note[aria-pressed="true"]')).toHaveCount(0)
})
