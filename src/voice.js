/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Voice-note recording support: format choice, upload, and an IndexedDB buffer so a recording
// survives a failed upload or a closed tab until it reaches the server.

import { uploadVoiceNote } from './api.js'

export const MAX_RECORDING_MS = 5 * 60 * 1000

// First format this browser's MediaRecorder supports. Safari records MP4/AAC, the others WebM/Opus.
export function pickMimeType() {
	const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4;codecs=mp4a.40.2', 'audio/mp4', 'audio/ogg;codecs=opus']
	return candidates.find((type) => window.MediaRecorder?.isTypeSupported?.(type)) ?? ''
}

export function canRecord() {
	return Boolean(navigator.mediaDevices?.getUserMedia && window.MediaRecorder)
}

const DB_NAME = 'audioannotations'
const STORE = 'pendingVoiceNotes'

function openDb() {
	return new Promise((resolve, reject) => {
		const request = indexedDB.open(DB_NAME, 1)
		request.onupgradeneeded = () => request.result.createObjectStore(STORE, { keyPath: 'id' })
		request.onsuccess = () => resolve(request.result)
		request.onerror = () => reject(request.error)
	})
}

async function withStore(mode, fn) {
	const db = await openDb()
	try {
		return await new Promise((resolve, reject) => {
			const tx = db.transaction(STORE, mode)
			const result = fn(tx.objectStore(STORE))
			tx.oncomplete = () => resolve(result?.result ?? result)
			tx.onerror = () => reject(tx.error)
		})
	} finally {
		db.close()
	}
}

/**
 * Unsent recordings for one audio file, oldest first. Empty when IndexedDB is unavailable.
 *
 * @param fileId
 */
export async function listPending(fileId) {
	try {
		const all = await withStore('readonly', (store) => store.getAll())
		return all.filter((p) => p.fileId === fileId).sort((a, b) => a.createdAt - b.createdAt)
	} catch {
		return []
	}
}

export async function discardPending(id) {
	try {
		await withStore('readwrite', (store) => store.delete(id))
	} catch {
		// nothing stored, nothing to remove
	}
}

/**
 * Keep the recording locally, upload it, then forget it. On failure it stays buffered and the error is rethrown.
 *
 * @param {object} voice { fileId, startMs, endMs, durationMs, caption, visibility, blob, type }
 * @param {(fraction: number) => void} [onProgress]
 */
export async function saveVoiceNote(voice, onProgress) {
	const pending = { id: voice.id ?? `${voice.fileId}-${Date.now()}`, createdAt: voice.createdAt ?? Date.now(), ...voice }
	try {
		await withStore('readwrite', (store) => store.put(pending))
	} catch {
		// Private windows may refuse IndexedDB; upload anyway, just without the safety net.
	}
	const note = await uploadVoiceNote(pending.fileId, pending, onProgress)
	await discardPending(pending.id)
	return note
}
