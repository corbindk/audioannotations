/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Shared note helpers for the player and the sidebar.

export const NOTE_COLORS = {
	blue: '#0082c9',
	amber: '#f2a516',
	violet: '#8b5cf6',
	green: '#16a36a',
	red: '#e0334b',
	grey: '#8a93a1',
}

// Event-bus topic: payload { fileId }. Whoever changes notes emits it; the other view reloads.
export const NOTES_CHANGED = 'audioannotations:notes-changed'

// Event-bus topic: payload { fileId, id, startMs, endMs }. The sidebar asks the player to select and play a range.
export const NOTE_SELECTED = 'audioannotations:note-selected'

// Start and end both 0: a general comment on the whole recording, not tied to a time.
export const isGeneral = (note) => note.startMs === 0 && note.endMs === 0

/**
 * Stack overlapping notes into lanes, first fit by start time.
 *
 * @param {{id: number, startMs: number, endMs: number}[]} notes
 * @return {{lanes: Map<number, number>, count: number}} note id → lane index, and the number of lanes
 */
export function assignLanes(notes) {
	const laneEnds = []
	const lanes = new Map()
	for (const note of [...notes].sort((a, b) => a.startMs - b.startMs || a.id - b.id)) {
		let lane = laneEnds.findIndex((end) => end <= note.startMs)
		if (lane === -1) {
			lane = laneEnds.length
			laneEnds.push(0)
		}
		laneEnds[lane] = Math.max(note.endMs, note.startMs + 1)
		lanes.set(note.id, lane)
	}
	return { lanes, count: laneEnds.length }
}

// General comments first, then by time.
export function sortNotes(notes) {
	return [...notes].sort((a, b) => isGeneral(b) - isGeneral(a) || a.startMs - b.startMs || a.id - b.id)
}
