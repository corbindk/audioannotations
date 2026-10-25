<!--
  - SPDX-FileCopyrightText: 2026 Corbin Keener
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="audioannotations">
		<form class="audioannotations__compose" @submit.prevent="add">
			<div class="audioannotations__range">
				<NcTextField
					v-model="form.start"
					:label="t('audioannotations', 'Start')"
					placeholder="1:05"
					:error="form.start !== '' && startMs === null" />
				<NcTextField
					v-model="form.end"
					:label="t('audioannotations', 'End')"
					placeholder="1:48"
					:error="form.end !== '' && endMs === null" />
			</div>
			<div class="audioannotations__modes" role="group" :aria-label="t('audioannotations', 'Note type')">
				<NcButton :variant="mode === 'text' ? 'secondary' : 'tertiary'" :aria-pressed="mode === 'text'" @click="mode = 'text'">
					{{ t('audioannotations', 'Text') }}
				</NcButton>
				<NcButton :variant="mode === 'voice' ? 'secondary' : 'tertiary'" :aria-pressed="mode === 'voice'" @click="mode = 'voice'">
					{{ t('audioannotations', 'Voice') }}
				</NcButton>
			</div>
			<VoiceComposer
				v-if="mode === 'voice' && fileId"
				:fileId="fileId"
				:startMs="rangeOk ? startMs : null"
				:endMs="rangeOk ? endMs : null"
				@saved="onVoiceSaved" />
			<NcTextArea
				v-if="mode === 'text'"
				v-model="form.body"
				:label="t('audioannotations', 'Note')"
				resize="vertical"
				rows="3" />
			<div v-if="mode === 'text'" class="audioannotations__compose-row">
				<NcCheckboxRadioSwitch v-model="form.private">
					{{ t('audioannotations', 'Only visible to me') }}
				</NcCheckboxRadioSwitch>
				<NcButton type="submit" variant="primary" :disabled="!canSave || saving">
					{{ t('audioannotations', 'Add note') }}
				</NcButton>
			</div>
			<p v-if="rangeHint" class="audioannotations__hint">
				{{ rangeHint }}
			</p>
		</form>

		<PendingVoiceNotes :fileId="fileId" @uploaded="onVoiceSaved" />

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="notes.length === 0"
			:name="t('audioannotations', 'No notes yet')"
			:description="t('audioannotations', 'Add a start and end time, then write what happens in that part of the recording.')" />

		<template v-else>
			<NcNoteCard v-if="notes.some(n => n.stale)" type="warning">
				{{ t('audioannotations', 'This recording changed length after some notes were made, so their times may be off.') }}
			</NcNoteCard>
			<ul class="audioannotations__list">
				<NoteItem
					v-for="note in notes"
					:key="note.id"
					:note="note"
					@changed="onChanged"
					@delete="remove"
					@select="select" />
			</ul>
			<p class="audioannotations__export">
				{{ t('audioannotations', 'Export') }}:
				<a :href="exportUrl(fileId, 'md')">{{ t('audioannotations', 'Markdown') }}</a>
				·
				<a :href="exportUrl(fileId, 'audacity')">{{ t('audioannotations', 'Audacity labels') }}</a>
			</p>
		</template>
	</div>
</template>

<script setup>
import { emit, subscribe, unsubscribe } from '@nextcloud/event-bus'
import { t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NoteItem from './NoteItem.vue'
import PendingVoiceNotes from './PendingVoiceNotes.vue'
import VoiceComposer from './VoiceComposer.vue'
import { createNote, deleteNote, errorMessage, exportUrl, listNotes } from './api.js'
import { NOTE_SELECTED, NOTES_CHANGED, sortNotes } from './notes.js'
import { parseTime } from './time.js'

const fileId = ref(null)
const notes = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const form = reactive({ start: '', end: '', body: '', private: false })
const mode = ref('text')

// Start and End both left empty: a general comment on the whole recording (stored as 0–0).
const general = computed(() => form.start.trim() === '' && form.end.trim() === '')
const startMs = computed(() => general.value ? 0 : parseTime(form.start))
const endMs = computed(() => general.value ? 0 : parseTime(form.end))
const rangeOk = computed(() => startMs.value !== null && endMs.value !== null && endMs.value >= startMs.value)
const canSave = computed(() => rangeOk.value && form.body.trim() !== '')
const rangeHint = computed(() => {
	if (general.value) {
		return t('audioannotations', 'No start or end: this will be a General Comment.')
	}
	if (form.start === '' || form.end === '') {
		return ''
	}
	if (startMs.value === null || endMs.value === null) {
		return t('audioannotations', 'Use minutes and seconds, like 1:05 or 1:02:03.')
	}
	return rangeOk.value ? '' : t('audioannotations', 'End must not be before start.')
})

async function load() {
	loading.value = true
	error.value = ''
	try {
		notes.value = await listNotes(fileId.value)
	} catch (e) {
		error.value = errorMessage(e, t('audioannotations', 'Could not load notes. Reload the page to try again.'))
	} finally {
		loading.value = false
	}
}

async function add() {
	if (!canSave.value) {
		return
	}
	saving.value = true
	error.value = ''
	try {
		const note = await createNote(fileId.value, {
			startMs: startMs.value,
			endMs: endMs.value,
			body: form.body,
			visibility: form.private ? 'private' : 'shared',
		})
		notes.value = sortNotes([...notes.value, note])
		form.body = ''
		emit(NOTES_CHANGED, { fileId: fileId.value, source: 'sidebar' })
	} catch (e) {
		error.value = errorMessage(e, t('audioannotations', 'Could not save the note. Check your connection and try again.'))
	} finally {
		saving.value = false
	}
}

function onChanged(note) {
	notes.value = notes.value.map((n) => n.id === note.id ? note : n)
	emit(NOTES_CHANGED, { fileId: fileId.value, source: 'sidebar' })
}

// Ask the open player (if any) to select and play this range.
function select(note) {
	emit(NOTE_SELECTED, { fileId: fileId.value, id: note.id, startMs: note.startMs, endMs: note.endMs })
}

async function remove(note) {
	const what = note.kind === 'voice'
		? t('audioannotations', 'Delete this voice note? Your recording moves to your trash bin.')
		: t('audioannotations', 'Delete this note? This cannot be undone.')

	if (!window.confirm(what)) {
		return
	}
	error.value = ''
	try {
		await deleteNote(note.id)
		notes.value = notes.value.filter((n) => n.id !== note.id)
		emit(NOTES_CHANGED, { fileId: fileId.value, source: 'sidebar' })
	} catch (e) {
		error.value = errorMessage(e, t('audioannotations', 'Could not delete the note.'))
	}
}

function setFile(fileInfo) {
	if (fileInfo?.id === fileId.value) {
		return
	}
	fileId.value = fileInfo.id
	notes.value = []
	load()
}

function onVoiceSaved(note) {
	notes.value = sortNotes([...notes.value.filter((n) => n.id !== note.id), note])
	emit(NOTES_CHANGED, { fileId: fileId.value, source: 'sidebar' })
}

function onNotesChanged(payload) {
	if (payload?.fileId === fileId.value && payload?.source !== 'sidebar') {
		load()
	}
}

onMounted(() => subscribe(NOTES_CHANGED, onNotesChanged))
onBeforeUnmount(() => unsubscribe(NOTES_CHANGED, onNotesChanged))

defineExpose({ setFile })
</script>

<style scoped>
.audioannotations {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;
}

.audioannotations__compose {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.audioannotations__range {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}

.audioannotations__compose-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	flex-wrap: wrap;
}

.audioannotations__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.audioannotations__modes {
	display: flex;
	gap: 4px;
}

.audioannotations__export {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.audioannotations__export a {
	text-decoration: underline;
}

.audioannotations__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>
