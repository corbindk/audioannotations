/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Regenerates screenshots/*.png against a running Nextcloud with the app enabled (`make screenshots`).
// It creates two demo users (maya, jordan), uploads a generated recording to maya's Recordings folder,
// shares it with jordan, replaces the notes on it with a fixed demo set and then photographs the app as
// jordan, the reviewer. Needs NC_URL (default http://localhost:8090), NC_ADMIN_PASSWORD and DEMO_PASSWORD.

import { chromium, devices, request } from '@playwright/test'
import { Buffer } from 'node:buffer'
import { mkdirSync } from 'node:fs'
import process from 'node:process'

const BASE = process.env.NC_URL ?? 'http://localhost:8090'
const ADMIN = process.env.NC_ADMIN_PASSWORD
const DEMO = process.env.DEMO_PASSWORD
const FILE = 'Rehearsal take 3.wav'
const OUT = 'screenshots'
const USERS = { maya: 'Maya Chen', jordan: 'Jordan Ellis' }

if (!ADMIN || !DEMO) {
	throw new Error('Set NC_ADMIN_PASSWORD and DEMO_PASSWORD')
}

function auth(user, password = DEMO) {
	return {
		Authorization: 'Basic ' + Buffer.from(`${user}:${password}`).toString('base64'),
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
}
// One cookie-free connection per user: Nextcloud would otherwise keep using the first user's session cookie.
const contexts = {}
async function api(user) {
	contexts[user] ??= await request.newContext({ baseURL: BASE })
	return contexts[user]
}
const dav = (user, path) => `/remote.php/dav/files/${user}/${path.split('/').map(encodeURIComponent).join('/')}`
const ocs = '/ocs/v2.php/apps/audioannotations/api/v1'
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

/**
 * About 4:30 of mono 16 kHz audio: phrases of uneven length and loudness with short pauses and a
 * syllable-like flutter, so the waveform looks like a sung or spoken take rather than a test tone.
 */
function demoWav(seconds = 270, rate = 16000) {
	const samples = seconds * rate
	const data = Buffer.alloc(44 + samples * 2)
	data.write('RIFF', 0)
	data.writeUInt32LE(36 + samples * 2, 4)
	data.write('WAVEfmt ', 8)
	data.writeUInt32LE(16, 16)
	data.writeUInt16LE(1, 20)
	data.writeUInt16LE(1, 22)
	data.writeUInt32LE(rate, 24)
	data.writeUInt32LE(rate * 2, 28)
	data.writeUInt16LE(2, 32)
	data.writeUInt16LE(16, 34)
	data.write('data', 36)
	data.writeUInt32LE(samples * 2, 40)
	let seed = 7
	const random = () => (seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648
	// Phrases: [start, end, loudness], separated by pauses of 0.3-1.6 s.
	const phrases = []
	for (let t = 0.5; t < seconds - 1;) {
		const length = 1.5 + random() * 7
		phrases.push([t, Math.min(seconds - 0.5, t + length), 0.35 + random() * 0.65])
		t += length + 0.3 + random() * 1.3
	}
	let current = 0
	for (let i = 0; i < samples; i++) {
		const t = i / rate
		while (current < phrases.length - 1 && t > phrases[current][1]) {
			current++
		}
		const [start, end, loudness] = phrases[current]
		let envelope = 0
		if (t >= start && t <= end) {
			const edge = Math.min(t - start, end - t)
			envelope = loudness * Math.min(1, edge / 0.25) * (0.6 + 0.4 * Math.abs(Math.sin(2 * Math.PI * (3 + current % 3) * t)))
		}
		const note = 160 + 40 * (current % 7)
		const tone = Math.sin(2 * Math.PI * note * t) * 0.6 + Math.sin(2 * Math.PI * note * 2.01 * t) * 0.25
		const value = (tone + (random() * 2 - 1) * 0.2) * envelope
		data.writeInt16LE(Math.max(-32767, Math.min(32767, Math.round(value * 24000))), 44 + i * 2)
	}
	return data
}

async function setUp() {
	for (const [user, displayName] of Object.entries(USERS)) {
		const res = await (await api('admin')).get(`/ocs/v2.php/cloud/users/${user}`, { headers: auth('admin', ADMIN) })
		if (res.status() === 404) {
			await (await api('admin')).post('/ocs/v2.php/cloud/users', { headers: auth('admin', ADMIN), form: { userid: user, password: DEMO, displayName } })
		}
	}
	await (await api('maya')).fetch(dav('maya', 'Recordings'), { method: 'MKCOL', headers: auth('maya') })
	await (await api('maya')).put(dav('maya', `Recordings/${FILE}`), { headers: auth('maya'), data: demoWav() })
	const shares = await (await api('maya')).get('/ocs/v2.php/apps/files_sharing/api/v1/shares', { headers: auth('maya'), params: { path: '/Recordings' } })
	if (!(await shares.json()).ocs.data.some((share) => share.share_with === 'jordan')) {
		await (await api('maya')).post('/ocs/v2.php/apps/files_sharing/api/v1/shares', { headers: auth('maya'), form: { path: '/Recordings', shareType: 0, shareWith: 'jordan', permissions: 1 } })
	}
	const propfind = await (await api('jordan')).fetch(dav('jordan', `Recordings/${FILE}`), {
		method: 'PROPFIND',
		headers: { ...auth('jordan'), Depth: '0' },
		data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	const id = Number((await propfind.text()).match(/<oc:fileid>(\d+)</)[1])

	// A fixed set of notes: start from none.
	for (const user of Object.keys(USERS)) {
		const notes = (await (await (await api(user)).get(`${ocs}/files/${id}/notes`, { headers: auth(user) })).json()).ocs.data
		for (const note of notes.filter((n) => n.owner.uid === user)) {
			await (await api(user)).delete(`${ocs}/notes/${note.id}`, { headers: auth(user) })
		}
	}
	const add = async (user, startMs, endMs, body, color = 'blue') => (await (await (await api(user)).post(`${ocs}/files/${id}/notes`, { headers: auth(user), form: { startMs, endMs, body, color, visibility: 'shared' } })).json()).ocs.data
	await add('maya', 0, 0, 'Strong take overall. Let\'s tighten the bridge before Friday\'s session.', 'green')
	await add('jordan', 12000, 31000, 'Intro feels rushed. Try counting in two bars before the first phrase.')
	const keep = await add('jordan', 66000, 98000, 'Lovely dynamics here. Keep this exactly as it is.', 'green')
	await add('maya', 150000, 171000, 'Tuning drifts on the held note.', 'amber')
	await add('jordan', 205000, 224000, 'The ending lands well, maybe hold the last chord a little longer.')
	await (await api('maya')).post(`${ocs}/notes/${keep.id}/replies`, { headers: auth('maya'), form: { body: 'Agreed, that is the take to match.' } })

	// Wait for the background job to build the waveform (cron runs every 5 minutes).
	for (let i = 0; i < 80; i++) {
		const res = await (await api('jordan')).get(`${ocs}/files/${id}/waveform`, { headers: auth('jordan') })
		if (res.status() === 200) {
			break
		}
		await sleep(5000)
	}
	return id
}

async function login(context) {
	const page = await context.newPage()
	await page.goto(`${BASE}/login`)
	await page.locator('#user').fill('jordan')
	await page.locator('#password').fill(DEMO)
	await page.locator('form button[type="submit"]').click()
	await page.waitForURL((url) => !url.pathname.startsWith('/login'))
	// New accounts get Nextcloud's welcome dialog; close it so it is not in the pictures.
	await sleep(2500)
	await page.keyboard.press('Escape')
	await sleep(500)
	return page
}

async function openPlayer(page, id) {
	const url = `${BASE}/apps/files/files/${id}?dir=/Recordings&openfile=true`
	await page.goto(url)
	if (!(await page.locator('.anp__wave canvas, .anp__wave div').first().isVisible({ timeout: 20000 }).catch(() => false))) {
		await page.goto(url)
	}
	await page.locator('.anp__wave').waitFor()
	await sleep(1500)
}

async function dragOnWave(page, from, to) {
	const box = await page.locator('.anp__wave').boundingBox()
	const y = box.y + box.height / 2
	await page.mouse.move(box.x + box.width * from, y)
	await page.mouse.down()
	await page.mouse.move(box.x + box.width * to, y, { steps: 12 })
	await page.mouse.up()
}

mkdirSync(OUT, { recursive: true })
const id = await setUp()
const browser = await chromium.launch({ args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] })

// Desktop
const desktop = await browser.newContext({ viewport: { width: 1280, height: 800 }, colorScheme: 'light', permissions: ['microphone'] })
let page = await login(desktop)
await openPlayer(page, id)
await page.locator('.anp__notes summary').click()
await sleep(500)
await page.screenshot({ path: `${OUT}/player.png` })

await page.locator('.anp__notes summary').click()
await dragOnWave(page, 0.52, 0.6)
await page.locator('.anp__dock input').fill('Breathe before the high note so it does not clip.')
await sleep(500)
await page.screenshot({ path: `${OUT}/selection.png` })

await page.locator('.anp__dock input').fill('')
await page.locator('button[aria-label="Clear selection"]').click()
await page.locator('.anp__lanes-track > *').nth(1).click()
await sleep(800)
await page.screenshot({ path: `${OUT}/note.png` })

await page.goto(`${BASE}/apps/files/files/${id}?dir=/Recordings`)
await page.waitForFunction(() => window.OCA?.Files?.Sidebar?.open)
await page.evaluate(async (path) => {
	await window.OCA.Files.Sidebar.open(path)
	window.OCA.Files.Sidebar.setActiveTab('audioannotations')
}, `/Recordings/${FILE}`)
await page.locator('.ani').first().waitFor()
await sleep(1000)
await page.screenshot({ path: `${OUT}/sidebar.png` })
await desktop.close()

// Phone
const phone = await browser.newContext({ ...devices['Pixel 7'], deviceScaleFactor: 2, colorScheme: 'light', permissions: ['microphone'] })
page = await login(phone)
await openPlayer(page, id)
const wave = await page.locator('.anp__wave').boundingBox()
await page.touchscreen.tap(wave.x + 5, wave.y + 5)
await page.evaluate(() => {
	const zoom = document.querySelector('.anp__zoom input')
	zoom.value = 6
	zoom.dispatchEvent(new Event('input', { bubbles: true }))
})
await page.locator('.anp__lanes-track > *').nth(1).click()
await sleep(1000)
await page.screenshot({ path: `${OUT}/phone-zoomed.png` })

await page.locator('.anp__lanes-track > *').nth(1).click()
await dragOnWave(page, 0.3, 0.45)
await page.locator('.anp__dock button[aria-label="Record a voice note"]').click()
await page.locator('.anp__dock').getByRole('button', { name: 'Record', exact: true }).click()
await sleep(2600)
await page.screenshot({ path: `${OUT}/phone-voice.png` })
await page.locator('.anp__dock').getByRole('button', { name: 'Stop' }).click()
await phone.close()

await browser.close()
for (const context of Object.values(contexts)) {
	await context.dispose()
}
process.stdout.write(`Screenshots written to ${OUT}/\n`)
