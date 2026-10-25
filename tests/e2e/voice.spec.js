/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { cleanup, dragOnWave, fileId, FOLDER, getAs, listNotes, MARK, openRecording, openSidebar, RECORDING_PATH } from './helpers.js'

// Plan cases 11 (voice note, length before playing) and 12 (failed upload kept and retried), with
// Chromium's fake microphone. Real phone microphones still need the manual run.
let id

test.beforeAll(async ({ request }) => {
	id = await fileId(request, 'tester', RECORDING_PATH)
})

test.afterEach(async ({ request }) => {
	await cleanup(request, id)
})

/** Record about two seconds in the dock and fill in the caption. Returns the dock locator. */
async function recordInDock(page, caption) {
	const dock = page.locator('.anp__dock')
	await dock.locator('button[aria-label="Record a voice note"]').click()
	await dock.getByRole('button', { name: 'Record', exact: true }).click()
	await expect(dock.getByRole('button', { name: 'Stop' })).toBeVisible()
	await page.waitForTimeout(2200)
	await dock.getByRole('button', { name: 'Stop' }).click()
	await expect(dock.locator('audio.anr__preview')).toBeVisible()
	await dock.getByLabel('Caption (optional)').fill(caption)
	return dock
}

test('records a voice note that others can play, with its length known before playing', async ({ page, request }) => {
	await openRecording(page, test, id)
	await dragOnWave(page, 0.3, 0.35)
	const dock = await recordInDock(page, `${MARK} voice take`)
	await dock.getByRole('button', { name: 'Save voice note' }).click()
	await expect(dock.locator('input[placeholder="Type a note, or tap the mic"]')).toBeVisible()

	const { notes } = await listNotes(request, 'tester', id)
	const note = notes.find((n) => n.body === `${MARK} voice take`)
	expect(note).toMatchObject({ kind: 'voice', general: false })
	expect(note.voiceDurationMs).toBeGreaterThan(1500)
	expect(note.voiceDurationMs).toBeLessThan(6000)

	// Another reviewer can stream it (Range request, as browsers do).
	const stream = await getAs('tester2', note.voiceUrl, { Range: 'bytes=0-' })
	expect(stream.status()).toBe(206)

	// The list shows a player whose length is known without pressing play.
	await page.locator('.anp__notes summary').click()
	const row = page.locator('.anp__notes li', { hasText: 'voice take' })
	const seconds = await row.locator('audio.anp__voice').evaluate((audio) => new Promise((resolve) => {
		if (Number.isFinite(audio.duration) && audio.duration > 0) {
			resolve(audio.duration)
		}
		audio.addEventListener('loadedmetadata', () => resolve(audio.duration), { once: true })
		audio.load()
	}))
	expect(seconds).toBeGreaterThan(1.5)
	expect(seconds).toBeLessThan(6)
})

test('a voice note that fails to upload is kept and can be uploaded later', async ({ page, request }, info) => {
	test.skip(info.project.name !== 'desktop', 'Uses the Files sidebar, which covers the player on a phone')
	await openRecording(page, test, id)
	await dragOnWave(page, 0.4, 0.45)
	await page.route('**/voice-notes**', (route) => route.abort('internetdisconnected'))
	const dock = await recordInDock(page, `${MARK} offline take`)
	await dock.getByRole('button', { name: 'Save voice note' }).click()
	await expect(dock.getByRole('alert')).toContainText('kept in this browser')
	await page.unroute('**/voice-notes**')
	expect((await listNotes(request, 'tester', id)).notes.some((n) => n.body === `${MARK} offline take`)).toBe(false)

	// After a reload the sidebar offers the kept recording.
	await page.goto(`/apps/files/files/${id}?dir=/${FOLDER}`)
	const tab = await openSidebar(page, `/${RECORDING_PATH}`)
	await tab.getByRole('button', { name: 'Upload' }).first().click()
	await expect.poll(async () => (await listNotes(request, 'tester', id)).notes.some((n) => n.body === `${MARK} offline take`)).toBe(true)
	await expect(tab.getByRole('button', { name: 'Upload' })).toHaveCount(0)
})
