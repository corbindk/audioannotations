/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, request as playwrightRequest } from '@playwright/test'
import { readFileSync } from 'node:fs'

// Test accounts. admin must exist; global setup creates tester and tester2 when they are missing.
// Passwords come from the environment (see make test-e2e).
export const USERS = {
	admin: process.env.NC_ADMIN_PASSWORD,
	tester: process.env.TEST_USER_PASSWORD,
	tester2: process.env.TEST_USER2_PASSWORD,
}

// Notes the tests create start with this, so cleanup never touches anyone's real notes.
export const MARK = 'e2e:'

// Global setup puts tests/fixtures/sample.mp3 (5:49, generated) in this folder of admin's files and shares
// the folder read-only with both testers, so the tests need no recordings of their own.
export const FOLDER = 'AudioAnnotationsE2E'
export const RECORDING = 'sample.mp3'
export const RECORDING_PATH = `${FOLDER}/${RECORDING}`
export const DISPLAY_NAMES = { tester: 'Test User', tester2: 'Test User 2' }

const ocs = '/ocs/v2.php/apps/audioannotations/api/v1'

function auth(user) {
	return { Authorization: 'Basic ' + Buffer.from(`${user}:${USERS[user]}`).toString('base64'), 'OCS-APIRequest': 'true', Accept: 'application/json' }
}

// One cookie-free API connection per user. A session cookie would win over the Authorization header in
// Nextcloud, so the test's own logged-in request fixture cannot be used to act as someone else.
const contexts = {}
async function api(user) {
	contexts[user] ??= await playwrightRequest.newContext({ baseURL: process.env.NC_URL ?? 'http://localhost:8090', storageState: { cookies: [], origins: [] } })
	return contexts[user]
}

/** File id of a file in the user's files, by path. */
export async function fileId(request, user, path) {
	const res = await (await api(user)).fetch(`/remote.php/dav/files/${user}/${path.split('/').map(encodeURIComponent).join('/')}`, {
		method: 'PROPFIND',
		headers: { ...auth(user), Depth: '0', 'Content-Type': 'application/xml' },
		data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	expect(res.status(), `PROPFIND ${path}`).toBe(207)
	return Number((await res.text()).match(/<oc:fileid>(\d+)</)[1])
}

export async function listNotes(request, user, id) {
	const res = await (await api(user)).get(`${ocs}/files/${id}/notes`, { headers: auth(user) })
	return { status: res.status(), notes: res.ok() ? (await res.json()).ocs.data : [] }
}

export async function createNote(request, user, id, note) {
	const res = await (await api(user)).post(`${ocs}/files/${id}/notes`, { headers: auth(user), form: { visibility: 'shared', ...note, body: MARK + ' ' + note.body } })
	expect(res.status()).toBe(201)
	return (await res.json()).ocs.data
}

/** Delete every note the tests made on this recording, as the user who made them. */
export async function cleanup(request, id) {
	for (const user of ['tester', 'tester2']) {
		const { notes } = await listNotes(request, user, id)
		for (const note of notes.filter((n) => n.owner.uid === user && (n.body ?? '').startsWith(MARK))) {
			await (await api(user)).delete(`${ocs}/notes/${note.id}`, { headers: auth(user) })
		}
	}
}

export async function webdav(request, user, method, path, options = {}) {
	return (await api(user)).fetch(`/remote.php/dav/files/${user}/${path.split('/').map(encodeURIComponent).join('/')}`, {
		method,
		...options,
		headers: { ...auth(user), ...(options.headers ?? {}) },
	})
}

export async function shareLink(request, path) {
	const res = await (await api('admin')).post('/ocs/v2.php/apps/files_sharing/api/v1/shares', { headers: auth('admin'), form: { path, shareType: 3, permissions: 1 } })
	expect(res.ok()).toBeTruthy()
	return (await res.json()).ocs.data
}

export async function unshare(request, shareId) {
	await (await api('admin')).delete(`/ocs/v2.php/apps/files_sharing/api/v1/shares/${shareId}`, { headers: auth('admin') })
}

/**
 * Open a recording in the player. Right after an app upgrade the first load sometimes shows the folder
 * instead of the file (a known, unexplained glitch); that load is recorded as an annotation and retried
 * once, so the glitch is visible in the report without hiding a player that never opens.
 */
export async function openRecording(page, test, id) {
	const url = `/apps/files/files/${id}?dir=/${FOLDER}&openfile=true`
	await page.goto(url)
	const player = page.locator('.anp')
	try {
		await player.waitFor({ timeout: 20_000 })
	} catch {
		test.info().annotations.push({ type: 'first-load-miss', description: 'Viewer did not open the file on the first load' })
		await page.goto(url)
		await player.waitFor({ timeout: 20_000 })
	}
	await expect(page.locator('.anp__wave')).toBeVisible()
	return player
}

/** Drag across the waveform from one fraction of its width to another. */
export async function dragOnWave(page, from, to) {
	const box = await page.locator('.anp__wave').boundingBox()
	const y = box.y + box.height / 2
	await page.mouse.move(box.x + box.width * from, y)
	await page.mouse.down()
	await page.mouse.move(box.x + box.width * to, y, { steps: 12 })
	await page.mouse.up()
}

export async function clickOnWave(page, at) {
	const box = await page.locator('.anp__wave').boundingBox()
	await page.mouse.click(box.x + box.width * at, box.y + box.height / 2)
}

export const playButton = (page) => page.locator('.anp__transport button', { has: page.locator('.anp__play-label') })
export const timeText = async (page) => (await page.locator('.anp__time').textContent()).split('/')[0].trim()
/** The dock's "Note for 3:29.4 → 4:27.4" line, as [start, end] in m:ss. */
export async function selectedRange(page) {
	const text = await page.locator('.anp__dock-target').textContent()
	const m = text.match(/(\d+:\d\d)(?:\.\d)? → (\d+:\d\d)/)
	return m ? [m[1], m[2]] : null
}

/** Open the Files sidebar on a recording, on its Annotations tab. */
export async function openSidebar(page, path) {
	await page.waitForFunction(() => window.OCA?.Files?.Sidebar?.open)
	await page.evaluate(async (p) => {
		await window.OCA.Files.Sidebar.open(p)
		window.OCA.Files.Sidebar.setActiveTab('audioannotations')
	}, path)
	const tab = page.locator('.audioannotations')
	await tab.waitFor()
	return tab
}

/** Fetch with a user's credentials and no session (returns the APIRequestContext response). */
export async function getAs(user, url, headers = {}) {
	return (await api(user)).get(url, { headers: { ...auth(user), ...headers } })
}

/** Create the test users, the shared folder and the sample recording if they are not there yet. */
export async function prepareInstance() {
	const admin = await api('admin')
	const headers = auth('admin')
	for (const user of ['tester', 'tester2']) {
		const res = await admin.get(`/ocs/v2.php/cloud/users/${user}`, { headers })
		if (res.status() === 404) {
			const made = await admin.post('/ocs/v2.php/cloud/users', { headers, form: { userid: user, password: USERS[user], displayName: DISPLAY_NAMES[user] } })
			expect(made.ok(), `create ${user}`).toBeTruthy()
		}
	}
	await webdav(null, 'admin', 'MKCOL', FOLDER)
	if ((await webdav(null, 'admin', 'PROPFIND', RECORDING_PATH, { headers: { Depth: '0' } })).status() === 404) {
		const put = await webdav(null, 'admin', 'PUT', RECORDING_PATH, { data: readFileSync('tests/fixtures/sample.mp3') })
		expect(put.status(), 'upload sample').toBeLessThan(300)
	}
	const shares = await admin.get('/ocs/v2.php/apps/files_sharing/api/v1/shares', { headers, params: { path: `/${FOLDER}` } })
	const sharedWith = (await shares.json()).ocs.data.map((share) => share.share_with)
	for (const user of ['tester', 'tester2'].filter((u) => !sharedWith.includes(u))) {
		const res = await admin.post('/ocs/v2.php/apps/files_sharing/api/v1/shares', { headers, form: { path: `/${FOLDER}`, shareType: 0, shareWith: user, permissions: 1 } })
		expect(res.ok(), `share with ${user}`).toBeTruthy()
	}
}
