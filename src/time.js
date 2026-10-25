/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// "1:05", "01:02:03", "75" or "1:05.5" → milliseconds. Returns null for anything else.
export function parseTime(text) {
	const value = String(text ?? '').trim()
	if (!/^\d+(:\d{1,2}){0,2}(\.\d{1,3})?$/.test(value)) {
		return null
	}
	const [whole, fraction = ''] = value.split('.')
	const parts = whole.split(':').map(Number)
	if (parts.slice(1).some((p) => p > 59)) {
		return null
	}
	const seconds = parts.reduce((total, part) => total * 60 + part, 0)
	return seconds * 1000 + Number(fraction.padEnd(3, '0'))
}

// Milliseconds → "m:ss" or "h:mm:ss".
export function formatTime(ms) {
	const total = Math.floor(ms / 1000)
	const h = Math.floor(total / 3600)
	const m = Math.floor((total % 3600) / 60)
	const s = String(total % 60).padStart(2, '0')
	return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`
}
