/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { cleanup, createNote, fileId, listNotes, MARK, openRecording, RECORDING_PATH, shareLink, unshare, webdav } from './helpers.js'

// Plan cases 15 and 17, and public share links (review round 1). API checks run once, on desktop.
let id

test.beforeAll(async ({ request }) => {
	id = await fileId(request, 'tester', RECORDING_PATH)
})

test.afterEach(async ({ request }) => {
	await cleanup(request, id)
})

test('another reviewer sees shared notes, not private ones, and cannot edit them', async ({ request, browser }, info) => {
	test.skip(info.project.name !== 'desktop', 'API and second-user check: once is enough')
	await createNote(request, 'tester', id, { startMs: 1000, endMs: 2000, body: 'shared one' })
	await createNote(request, 'tester', id, { startMs: 3000, endMs: 4000, body: 'private one', visibility: 'private' })
	const { notes } = await listNotes(request, 'tester2', id)
	const shared = notes.find((n) => n.body === `${MARK} shared one`)
	expect(shared).toMatchObject({ canEdit: false, canDelete: false })
	expect(notes.find((n) => n.body === `${MARK} private one`)).toBeUndefined()

	const context = await browser.newContext({ storageState: 'build/e2e-auth/tester2.json' })
	const page = await context.newPage()
	await openRecording(page, test, id)
	await page.locator('.anp__notes summary').click()
	await expect(page.locator('.anp__note', { hasText: 'shared one' })).toHaveCount(1)
	await expect(page.locator('.anp__note', { hasText: 'private one' })).toHaveCount(0)
	await context.close()
})

test('someone without access to a recording gets 404 for its notes', async ({ request }, info) => {
	test.skip(info.project.name !== 'desktop', 'API check: once is enough')
	// A copy only tester can see.
	const audio = await webdav(request, 'tester', 'GET', RECORDING_PATH)
	expect(audio.ok()).toBeTruthy()
	const put = await webdav(request, 'tester', 'PUT', 'e2e-private.mp3', { data: await audio.body() })
	expect(put.status()).toBeLessThan(300)
	try {
		const own = await fileId(request, 'tester', 'e2e-private.mp3')
		expect((await listNotes(request, 'tester', own)).status).toBe(200)
		expect((await listNotes(request, 'tester2', own)).status).toBe(404)
	} finally {
		await webdav(request, 'tester', 'DELETE', 'e2e-private.mp3')
	}
})

test('a logged-out visitor to a public link gets the standard player', async ({ request, browser }, info) => {
	test.skip(info.project.name !== 'desktop', 'Server-side check: once is enough')
	const share = await shareLink(request, `/${RECORDING_PATH}`)
	try {
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		const page = await context.newPage()
		const res = await page.goto(`/s/${share.token}`)
		expect(res.ok()).toBeTruthy()
		expect(await page.content()).not.toContain('audioannotations-viewer')
		await expect(page.locator('.anp')).toHaveCount(0)
		await context.close()
	} finally {
		await unshare(request, share.id)
	}
})
