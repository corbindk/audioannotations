/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { formatTime, parseTime } from '../../src/time.js'

describe('parseTime', () => {
	it.each([
		['75', 75000],
		['1:05', 65000],
		['01:02:03', 3723000],
		['1:05.5', 65500],
		['0:00.125', 125],
		[' 2:00 ', 120000],
	])('reads %j as %i ms', (text, ms) => {
		expect(parseTime(text)).toBe(ms)
	})

	it.each(['', 'abc', '1:60', '1:2:3:4', '-5', '1.2.3', null, undefined])('rejects %j', (text) => {
		expect(parseTime(text)).toBeNull()
	})
})

describe('formatTime', () => {
	it.each([
		[0, '0:00'],
		[999, '0:00'],
		[65500, '1:05'],
		[3599000, '59:59'],
		[3723000, '1:02:03'],
	])('shows %i ms as %s', (ms, text) => {
		expect(formatTime(ms)).toBe(text)
	})
})
