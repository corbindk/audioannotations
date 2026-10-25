/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { assignLanes, isGeneral, sortNotes } from '../../src/notes.js'

const note = (id, startMs, endMs) => ({ id, startMs, endMs })

describe('isGeneral', () => {
	it('is true only for start = end = 0', () => {
		expect(isGeneral(note(1, 0, 0))).toBe(true)
		expect(isGeneral(note(2, 0, 5000))).toBe(false)
		expect(isGeneral(note(3, 4000, 4000))).toBe(false)
	})
})

describe('sortNotes', () => {
	it('puts General Comments first, then sorts by start time and id', () => {
		const sorted = sortNotes([note(5, 3000, 4000), note(4, 0, 0), note(3, 1000, 2000), note(2, 1000, 9000), note(1, 0, 0)])
		expect(sorted.map((n) => n.id)).toEqual([1, 4, 2, 3, 5])
	})

	it('does not change the array it was given', () => {
		const notes = [note(2, 5000, 6000), note(1, 0, 1000)]
		sortNotes(notes)
		expect(notes.map((n) => n.id)).toEqual([2, 1])
	})
})

describe('assignLanes', () => {
	it('puts non-overlapping notes in one lane', () => {
		const { lanes, count } = assignLanes([note(1, 0, 1000), note(2, 1000, 2000), note(3, 5000, 6000)])
		expect(count).toBe(1)
		expect([...lanes.values()]).toEqual([0, 0, 0])
	})

	it('stacks overlapping notes and reuses the first free lane', () => {
		const { lanes, count } = assignLanes([note(1, 0, 5000), note(2, 1000, 2000), note(3, 3000, 4000), note(4, 4500, 9000)])
		expect(count).toBe(2)
		expect(lanes.get(1)).toBe(0)
		expect(lanes.get(2)).toBe(1)
		expect(lanes.get(3)).toBe(1)
		expect(lanes.get(4)).toBe(1)
	})

	it('gives a zero-length note 1 ms, so a note starting at the same moment stacks above it', () => {
		const { lanes, count } = assignLanes([note(1, 2000, 2000), note(2, 2000, 3000), note(3, 2001, 2500)])
		expect(count).toBe(2)
		expect(lanes.get(3)).toBe(0)
	})
})
