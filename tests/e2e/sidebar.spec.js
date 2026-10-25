/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { cleanup, createNote, fileId, FOLDER, listNotes, MARK, openRecording, openSidebar, RECORDING_PATH } from './helpers.js'

// Plan cases 14 and 18: the Annotations tab in the Files sidebar. Desktop only: on a phone the
// sidebar takes the whole screen and the same component is used.
let id
const path = `/${RECORDING_PATH}`

test.beforeAll(async ({ request }) => {
	id = await fileId(request, 'tester', RECORDING_PATH)
})

test.beforeEach(async ({ page }, info) => {
	test.skip(info.project.name !== 'desktop', 'Sidebar tests run on desktop')
	await page.goto(`/apps/files/files/${id}?dir=/${FOLDER}`)
})

test.afterEach(async ({ request }) => {
	await cleanup(request, id)
})

test('shows avatars instead of names and marks General Comments', async ({ page, request }) => {
	await createNote(request, 'tester', id, { startMs: 0, endMs: 0, body: 'general from api' })
	await createNote(request, 'tester', id, { startMs: 61000, endMs: 64000, body: 'timed from api' })
	const tab = await openSidebar(page, path)
	const general = tab.locator('.ani', { hasText: 'general from api' })
	const timed = tab.locator('.ani', { hasText: 'timed from api' })
	await expect(general.locator('.ani__badge', { hasText: 'General' })).toBeVisible()
	await expect(timed.locator('.ani__time')).toHaveText('1:01–1:04')
	for (const note of [general, timed]) {
		await expect(note.locator('.ani__who img')).toBeVisible()
		await expect(note.locator('.ani__head strong')).toHaveCount(0)
	}
	await expect(tab.getByRole('link', { name: 'Markdown' })).toHaveAttribute('href', new RegExp(`/apps/audioannotations/export/${id}\\?format=md`))
	await expect(tab.getByRole('link', { name: 'Audacity labels' })).toHaveAttribute('href', new RegExp('format=audacity'))
})

test('typed start and end make a timed note; empty fields make a General Comment', async ({ page, request }) => {
	const tab = await openSidebar(page, path)
	await tab.getByLabel('Start', { exact: true }).fill('1:05')
	await tab.getByLabel('End', { exact: true }).fill('1:10')
	await tab.getByLabel('Note', { exact: true }).fill(`${MARK} typed range`)
	await tab.getByRole('button', { name: 'Add note' }).click()
	await expect(tab.locator('.ani', { hasText: 'typed range' })).toBeVisible()

	await tab.getByLabel('Start', { exact: true }).fill('')
	await tab.getByLabel('End', { exact: true }).fill('')
	await expect(tab.locator('.audioannotations__hint')).toContainText('General Comment')
	await tab.getByLabel('Note', { exact: true }).fill(`${MARK} typed general`)
	await tab.getByRole('button', { name: 'Add note' }).click()
	await expect(tab.locator('.ani', { hasText: 'typed general' }).locator('.ani__badge', { hasText: 'General' })).toBeVisible()

	const { notes } = await listNotes(request, 'tester', id)
	expect(notes.find((n) => n.body === `${MARK} typed range`)).toMatchObject({ startMs: 65000, endMs: 70000, general: false })
	expect(notes.find((n) => n.body === `${MARK} typed general`)).toMatchObject({ startMs: 0, endMs: 0, general: true })
})

test('reply, edit, resolve and delete your own note', async ({ page, request }) => {
	await createNote(request, 'tester', id, { startMs: 120000, endMs: 125000, body: 'to be worked on' })
	const tab = await openSidebar(page, path)
	const note = tab.locator('.ani', { hasText: 'to be worked on' })

	await note.getByRole('button', { name: 'Reply' }).click()
	await note.getByLabel('Reply').fill('first reply')
	await note.getByRole('button', { name: 'Send' }).click()
	await expect(note.locator('.ani__reply', { hasText: 'first reply' })).toBeVisible()

	// While editing, the note's text is inside a text box, so find the note by its reply from here on.
	const edited = tab.locator('.ani', { has: page.locator('.ani__reply', { hasText: 'first reply' }) })
	await edited.getByRole('button', { name: 'Edit' }).click()
	await edited.getByLabel('Note').fill(`${MARK} worked on`)
	await edited.getByRole('button', { name: 'Save' }).click()
	await expect(edited.locator('.ani__body').first()).toHaveText(`${MARK} worked on`)

	await edited.getByRole('button', { name: 'Resolve' }).click()
	await expect(edited.locator('.ani__badge', { hasText: 'Resolved' })).toBeVisible()
	await edited.getByRole('button', { name: 'Reopen' }).click()
	await expect(edited.locator('.ani__badge', { hasText: 'Resolved' })).toHaveCount(0)

	page.once('dialog', (dialog) => dialog.accept())
	await edited.getByRole('button', { name: 'Delete' }).last().click()
	await expect(edited).toHaveCount(0)
	expect((await listNotes(request, 'tester', id)).notes.some((n) => (n.body ?? '').includes('worked on'))).toBe(false)
})

test('clicking a time in the sidebar selects that range in the open player', async ({ page, request }) => {
	await createNote(request, 'tester', id, { startMs: 200000, endMs: 206000, body: 'jump here' })
	await openRecording(page, test, id)
	const tab = await openSidebar(page, path)
	await tab.locator('.ani', { hasText: 'jump here' }).locator('.ani__time').click()
	await expect(page.locator('.anp__dock-target')).toContainText('3:20.0 → 3:26.0')
})
