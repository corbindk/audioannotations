/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

const headers = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

const url = (path, params) => generateOcsUrl('apps/audioannotations/api/v1' + path, params)

export async function listNotes(fileId) {
	const { data } = await axios.get(url('/files/{fileId}/notes', { fileId }), { headers })
	return data.ocs.data
}

export async function createNote(fileId, note) {
	const { data } = await axios.post(url('/files/{fileId}/notes', { fileId }), note, { headers })
	return data.ocs.data
}

export async function updateNote(id, changes) {
	const { data } = await axios.patch(url('/notes/{id}', { id }), changes, { headers })
	return data.ocs.data
}

export async function deleteNote(id) {
	await axios.delete(url('/notes/{id}', { id }), { headers })
}

export async function addReply(noteId, body) {
	const { data } = await axios.post(url('/notes/{id}/replies', { id: noteId }), { body }, { headers })
	return data.ocs.data
}

export async function deleteReply(id) {
	await axios.delete(url('/replies/{id}', { id }), { headers })
}

// A download link for the notes on one recording: format 'md' or 'audacity'.
export function exportUrl(fileId, format) {
	return generateUrl('/apps/audioannotations/export/{fileId}?format={format}', { fileId, format })
}

// { status: 'ready', durationMs, peaks, overview } | { status: 'pending' | 'skipped' | 'failed', reason? }
export async function getWaveform(fileId) {
	const { data } = await axios.get(url('/files/{fileId}/waveform', { fileId }), { headers })
	return data.ocs.data
}

const EXTENSIONS = { 'audio/webm': 'webm', 'audio/mp4': 'm4a', 'audio/ogg': 'ogg' }

export async function uploadVoiceNote(fileId, voice, onProgress) {
	const form = new FormData()
	const ext = EXTENSIONS[voice.type.split(';')[0]] ?? 'webm'
	form.append('audio', voice.blob, `voice-note.${ext}`)
	form.append('startMs', String(voice.startMs))
	form.append('endMs', String(voice.endMs))
	form.append('durationMs', String(voice.durationMs))
	form.append('caption', voice.caption ?? '')
	form.append('visibility', voice.visibility ?? 'shared')
	const { data } = await axios.post(url('/files/{fileId}/voice-notes', { fileId }), form, {
		headers,
		onUploadProgress: (e) => onProgress?.(e.total ? e.loaded / e.total : 0),
	})
	return data.ocs.data
}

// The server's own message when there is one, else the generic one.
export function errorMessage(error, fallback) {
	return error?.response?.data?.ocs?.data?.message ?? fallback
}
