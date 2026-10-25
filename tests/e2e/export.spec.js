/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { cleanup, createNote, fileId, getAs, MARK, RECORDING_PATH } from './helpers.js'

// Plan case 19: Markdown and Audacity exports. Server-side, so desktop only.
let id

test.beforeAll(async ({ request }) => {
	id = await fileId(request, 'tester', RECORDING_PATH)
})

test.beforeEach(async ({ request }, info) => {
	test.skip(info.project.name !== 'desktop', 'Server-side check: once is enough')
	await createNote(request, 'tester', id, { startMs: 125500, endMs: 131000, body: 'later note' })
	await createNote(request, 'tester', id, { startMs: 0, endMs: 0, body: 'overall' })
	await createNote(request, 'tester', id, { startMs: 3000, endMs: 4500, body: 'early note' })
	await createNote(request, 'tester', id, { startMs: 5000, endMs: 6000, body: 'private note', visibility: 'private' })
})

test.afterEach(async ({ request }) => {
	await cleanup(request, id)
})

/** The export's lines that belong to this test's notes, in file order. */
function ours(text) {
	return text.split('\n').filter((line) => line.includes(MARK))
}

test('Markdown lists General Comments first, then notes in time order', async () => {
	const res = await getAs('tester', `/apps/audioannotations/export/${id}?format=md`)
	expect(res.status()).toBe(200)
	expect(res.headers()['content-type']).toContain('text/markdown')
	expect(res.headers()['content-disposition']).toContain('sample - notes.md')
	const lines = ours(await res.text())
	expect(lines).toEqual([
		expect.stringMatching(/^- \*\*General\*\* · Test User: e2e: overall$/),
		expect.stringMatching(/^- \*\*0:03–0:04\*\* · Test User: e2e: early note$/),
		expect.stringMatching(/^- \*\*0:05–0:06\*\* · Test User \(private\): e2e: private note$/),
		expect.stringMatching(/^- \*\*2:05–2:11\*\* · Test User: e2e: later note$/),
	])
})

test('Audacity labels are tab-separated seconds, General Comments as a point at 0', async () => {
	const res = await getAs('tester', `/apps/audioannotations/export/${id}?format=audacity`)
	expect(res.status()).toBe(200)
	expect(res.headers()['content-disposition']).toContain('sample - labels.txt')
	expect(ours(await res.text())).toEqual([
		'0.000000\t0.000000\t[general] Test User: e2e: overall',
		'3.000000\t4.500000\tTest User: e2e: early note',
		'5.000000\t6.000000\tTest User: e2e: private note',
		'125.500000\t131.000000\tTest User: e2e: later note',
	])
})

test('another user\'s export leaves out private notes', async () => {
	const text = await (await getAs('tester2', `/apps/audioannotations/export/${id}?format=md`)).text()
	expect(text).toContain('e2e: early note')
	expect(text).not.toContain('e2e: private note')
})
