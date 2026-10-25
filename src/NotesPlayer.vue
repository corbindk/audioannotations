<!--
  - SPDX-FileCopyrightText: 2026 Corbin Keener
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div
		ref="rootEl"
		class="anp"
		tabindex="-1"
		:aria-label="t('audioannotations', 'Audio player with notes. Space plays or pauses, [ and ] mark the start and end, L loops, arrows skip 5 seconds.')"
		@keydown="onKey">
		<!-- No file name here: the Viewer already shows it in its top bar. -->
		<p v-if="waveStatus !== 'ready'" class="anp__status">
			{{ statusText }}
		</p>
		<p v-if="notes.some(note => note.stale)" class="anp__warn">
			{{ t('audioannotations', 'This recording changed length after some notes were made, so their times may be off.') }}
		</p>

		<!-- The Viewer turns horizontal swipes into previous/next file. Keep touches on the waveform and note bars to
			 ourselves so dragging out a range doesn't switch recordings. Selection itself runs on pointer events;
			 the touch handlers here only do two-finger pinch zoom. -->
		<div
			class="anp__stage"
			@touchstart.stop="onPinchStart"
			@touchmove.stop="onPinchMove"
			@touchend.stop="onPinchEnd"
			@touchcancel.stop="onPinchEnd"
			@wheel="onWheelZoom">
			<div class="anp__lanes" :style="{ height: `${laneCount * LANE_HEIGHT + 4}px` }">
				<div ref="laneEl" class="anp__lanes-track" />
			</div>
			<div ref="waveEl" class="anp__wave" />
			<!-- While zoomed in: the whole recording in miniature, with the visible part highlighted. Tap or drag on it to move there. -->
			<div
				v-show="zoom > 0"
				ref="miniEl"
				class="anp__minimap"
				:aria-label="t('audioannotations', 'Overview of the whole recording')" />
		</div>

		<div class="anp__marks">
			<NcButton :variant="markedStartMs !== null && !selection ? 'primary' : 'secondary'" @click="markStart">
				{{ markedStartMs !== null && !selection
					? t('audioannotations', 'Start · {time} ✓', { time: formatTime(markedStartMs) })
					: t('audioannotations', 'Mark start') }}
			</NcButton>
			<NcButton variant="secondary" @click="markEnd">
				{{ t('audioannotations', 'Mark end') }}
			</NcButton>
			<!-- In the Mark row so it takes no line of its own under the waveform. -->
			<span class="anp__meta anp__count">{{ n('audioannotations', '%n note', '%n notes', notes.length) }}</span>
		</div>

		<!-- Time (and zoom on desktop) | Play, centred | Loop. -->
		<div class="anp__transport">
			<div class="anp__transport-side">
				<span class="anp__time">{{ formatTime(currentMs) }} / {{ formatTime(durationMs) }}</span>
				<!-- Desktop only: on touch screens, pinch the waveform instead. -->
				<label class="anp__zoom">
					{{ t('audioannotations', 'Zoom') }}
					<input
						v-model.number="zoom"
						type="range"
						min="0"
						:max="maxZoom"
						step="1"
						@input="applyZoom">
				</label>
			</div>
			<NcButton variant="primary" :aria-label="playing ? t('audioannotations', 'Pause') : t('audioannotations', 'Play')" @click="togglePlay">
				<!-- Both words share one cell, so the button is as wide as the longer one and never changes size. -->
				<span class="anp__play-label" aria-hidden="true">
					<span :class="{ 'anp__play-off': playing }">{{ t('audioannotations', 'Play') }}</span>
					<span :class="{ 'anp__play-off': !playing }">{{ t('audioannotations', 'Pause') }}</span>
				</span>
			</NcButton>
			<div class="anp__transport-side anp__transport-side--end">
				<NcCheckboxRadioSwitch v-model="loop" type="switch">
					{{ t('audioannotations', 'Loop selection') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<!-- Collapsed by default. With a note selected, only that note is shown; "Show all" deselects it. -->
		<details
			v-if="notes.length > 0"
			class="anp__notes"
			:open="focusedNote !== null || listOpen"
			@toggle="onListToggle">
			<summary @click="onSummaryClick">
				{{ focusedNote
					? n('audioannotations', 'Show the note', 'Show all %n notes', notes.length)
					: n('audioannotations', '%n note', '%n notes', notes.length) }}
			</summary>
			<ul>
				<li v-for="note in shownNotes" :key="note.id" :class="{ 'anp__note--resolved': note.resolved }">
					<button
						type="button"
						class="anp__note"
						:class="{ 'anp__note--selected': isSelected(note) }"
						:aria-pressed="isSelected(note)"
						@click="toggleNote(note)">
						<span class="anp__dot" :style="{ background: NOTE_COLORS[note.color] ?? NOTE_COLORS.blue }" />
						<span class="anp__note-time">{{ isGeneral(note) ? t('audioannotations', 'General') : `${formatTime(note.startMs)}–${formatTime(note.endMs)}` }}</span>
						<span class="anp__who" :title="note.owner.displayName">
							<NcAvatar
								:user="note.owner.uid"
								:displayName="note.owner.displayName"
								:size="20"
								hideStatus
								disableMenu
								disableTooltip
								aria-hidden="true" />
							<span class="hidden-visually">{{ note.owner.displayName }}</span>
						</span>
						<span class="anp__note-text">
							{{ note.kind === 'voice'
								? t('audioannotations', 'Voice note, {length}', { length: formatTime(note.voiceDurationMs ?? 0) }) + (note.body ? ` · ${note.body}` : '')
								: note.body }}
						</span>
					</button>
					<audio
						v-if="note.kind === 'voice' && note.voiceUrl"
						class="anp__voice"
						:src="note.voiceUrl"
						controls
						preload="metadata"
						:aria-label="t('audioannotations', 'Voice note by {name}', { name: note.owner.displayName })"
						@play="onVoicePlay" />
					<ul v-if="isSelected(note) && note.replies.length > 0" class="anp__replies">
						<li v-for="reply in note.replies" :key="reply.id">
							<span class="anp__who" :title="reply.owner.displayName">
								<NcAvatar
									:user="reply.owner.uid"
									:displayName="reply.owner.displayName"
									:size="20"
									hideStatus
									disableMenu
									disableTooltip
									aria-hidden="true" />
								<span class="hidden-visually">{{ reply.owner.displayName }}</span>
							</span>
							{{ reply.body }}
						</li>
					</ul>
				</li>
			</ul>
		</details>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<!-- New-note dock: stays at the bottom of the screen while the player scrolls. -->
		<section class="anp__dock" :aria-label="t('audioannotations', 'New note')">
			<div class="anp__dock-target">
				<span class="anp__dot" />
				<span>{{ target.label }}</span>
				<NcButton v-if="voiceOpen" variant="tertiary" @click="voiceOpen = false">
					{{ t('audioannotations', 'Type instead') }}
				</NcButton>
			</div>
			<template v-if="voiceOpen">
				<NcCheckboxRadioSwitch v-model="duck">
					{{ t('audioannotations', 'Keep playing quietly while I record') }}
				</NcCheckboxRadioSwitch>
				<VoiceComposer
					:fileId="fileId"
					:startMs="target.startMs"
					:endMs="target.endMs"
					@recording="onRecording"
					@saved="onVoiceSaved" />
			</template>
			<form v-else class="anp__dock-row" @submit.prevent="addNote">
				<label class="anp__dock-input">
					<span class="hidden-visually">{{ t('audioannotations', 'Note text') }}</span>
					<input
						v-model="draft"
						type="text"
						:placeholder="t('audioannotations', 'Type a note, or tap the mic')">
				</label>
				<button
					type="button"
					class="anp__dock-btn"
					:aria-label="t('audioannotations', 'Record a voice note')"
					@click="openVoice">
					<svg
						width="22"
						height="22"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						stroke-width="2"
						stroke-linecap="round"
						stroke-linejoin="round"
						aria-hidden="true"><rect
							x="9"
							y="3"
							width="6"
							height="11"
							rx="3" /><path d="M5 11a7 7 0 0014 0M12 18v3" /></svg>
				</button>
				<button
					type="submit"
					class="anp__dock-btn anp__dock-btn--send"
					:aria-label="t('audioannotations', 'Add note')"
					:disabled="draft.trim() === '' || saving">
					<svg
						width="20"
						height="20"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						stroke-width="2.5"
						stroke-linecap="round"
						stroke-linejoin="round"
						aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
				</button>
			</form>
		</section>
	</div>
</template>

<script setup>
import { emit, subscribe, unsubscribe } from '@nextcloud/event-bus'
import { n, t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import WaveSurfer from 'wavesurfer.js'
import MinimapPlugin from 'wavesurfer.js/dist/plugins/minimap.esm.js'
import RegionsPlugin from 'wavesurfer.js/dist/plugins/regions.esm.js'
import TimelinePlugin from 'wavesurfer.js/dist/plugins/timeline.esm.js'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import VoiceComposer from './VoiceComposer.vue'
import { createNote, errorMessage, getWaveform, listNotes } from './api.js'
import { assignLanes, isGeneral, NOTE_COLORS, NOTE_SELECTED, NOTES_CHANGED, sortNotes } from './notes.js'
import { formatTime as fmt } from './time.js'

const props = defineProps({
	fileId: { type: Number, required: true },
	src: { type: String, required: true },
})

const LANE_HEIGHT = 10
const POLL_MS = 5000

const rootEl = ref(null)
const waveEl = ref(null)
const laneEl = ref(null)
const miniEl = ref(null)
const notes = ref([])
const waveStatus = ref('loading')
const waveReason = ref('')
const durationMs = ref(0)
const currentMs = ref(0)
const playing = ref(false)
const zoom = ref(0)
// Zooming past ~3 px per peak shows no extra detail, just stretched shapes.
const maxZoom = ref(20)
const loop = ref(true)
const selection = ref(null)
const draft = ref('')
const saving = ref(false)
const error = ref('')
const voiceOpen = ref(false)
// Where a note without a range starts: the playhead when the writer typed the first character.
const draftAtMs = ref(null)
const DEFAULT_NOTE_MS = 5000
// "Mark start" pressed, waiting for "Mark end" (no selection yet).
const markedStartMs = ref(null)
// While recording: pause playback, or keep it going at 20% volume to talk over the passage.
const duck = ref(false)

// Kept out of Vue's reactivity on purpose.
let ws = null
let regions = null
let selRegion = null
let pollTimer = null
let audio = null
// The pinch in progress, if any.
let pinch = null
// While a pinch is under way (and briefly after), drag-selections the fingers start are thrown away.
let pinchGuard = false
let pinchGuardTimer = 0

// General comments have no time, so they get no bar on the timeline.
const timedNotes = computed(() => notes.value.filter((note) => !isGeneral(note)))
const laneCount = computed(() => Math.max(1, assignLanes(timedNotes.value).count))
const statusText = computed(() => ({
	loading: t('audioannotations', 'Loading…'),
	pending: t('audioannotations', 'The waveform is being prepared. This takes a few minutes for long recordings; you can play and annotate meanwhile.'),
	skipped: waveReason.value,
	failed: waveReason.value || t('audioannotations', 'The waveform could not be created for this recording.'),
}[waveStatus.value] ?? ''))

// What the dock's next note will cover: the selection, else 5 s from where the writer started typing.
const target = computed(() => {
	if (selection.value) {
		const { startMs, endMs } = selection.value
		return { startMs, endMs, label: t('audioannotations', 'Note for {start} → {end}', { start: formatTime(startMs, true), end: formatTime(endMs, true) }) }
	}
	const total = durationMs.value
	const at = draftAtMs.value ?? currentMs.value
	// At the very beginning with nothing selected: a general comment on the whole recording.
	if (at === 0) {
		return { startMs: 0, endMs: 0, general: true, label: t('audioannotations', 'General Comment') }
	}
	const startMs = total > 0 ? Math.max(0, Math.min(at, total - 1000)) : at
	const endMs = total > 0 ? Math.min(startMs + DEFAULT_NOTE_MS, total) : startMs + DEFAULT_NOTE_MS
	return { startMs, endMs, label: t('audioannotations', 'Note at {time} (5 s)', { time: formatTime(startMs) }) }
})

function formatTime(ms, tenths = false) {
	const base = fmt(ms)
	return tenths ? `${base}.${Math.floor((ms % 1000) / 100)}` : base
}

onMounted(async () => {
	audio = new Audio()
	audio.preload = 'metadata'
	audio.src = props.src
	rootEl.value?.focus({ preventScroll: true })
	await Promise.all([loadNotes(), loadWaveform()])
	subscribe(NOTES_CHANGED, onNotesChanged)
	subscribe(NOTE_SELECTED, onNoteSelected)
})

onBeforeUnmount(() => {
	unsubscribe(NOTES_CHANGED, onNotesChanged)
	unsubscribe(NOTE_SELECTED, onNoteSelected)
	clearTimeout(pollTimer)
	ws?.destroy()
	audio?.pause()
	audio?.removeAttribute('src')
	audio?.load()
})

watch(notes, drawLanes, { deep: true })

async function loadNotes() {
	try {
		notes.value = sortNotes(await listNotes(props.fileId))
	} catch (e) {
		error.value = errorMessage(e, t('audioannotations', 'Could not load notes.'))
	}
}

function onNotesChanged(payload) {
	if (payload?.fileId === props.fileId && payload?.source !== 'player') {
		loadNotes()
	}
}

async function loadWaveform() {
	let result
	try {
		result = await getWaveform(props.fileId)
	} catch (e) {
		result = { status: 'failed', reason: errorMessage(e, '') }
	}
	if (result.status !== 'ready') {
		// No peaks yet (or none coming): a flat line at the right length, from the audio's own metadata.
		durationMs.value = Math.round((await mediaDuration()) * 1000)
	}
	showWave(result)
	if (result.status === 'pending') {
		pollTimer = setTimeout(pollWaveform, POLL_MS)
	}
}

async function pollWaveform() {
	let result
	try {
		result = await getWaveform(props.fileId)
	} catch {
		pollTimer = setTimeout(pollWaveform, POLL_MS * 4)
		return
	}
	if (result.status === 'pending') {
		pollTimer = setTimeout(pollWaveform, POLL_MS)
		return
	}
	// Swap the flat line for the real waveform without losing the playback position.
	const resumeAt = ws?.getCurrentTime() ?? 0
	const wasPlaying = playing.value
	const keep = selection.value
	ws?.destroy()
	selRegion = null
	showWave(result)
	ws.once('ready', () => {
		ws.setTime(resumeAt)
		if (keep) {
			selectRange(keep.startMs, keep.endMs)
			ws.setTime(resumeAt)
		}
		if (wasPlaying) {
			ws.play()
		}
	})
}

function showWave(result) {
	waveStatus.value = result.status
	waveReason.value = result.reason ?? ''
	if (result.status === 'ready') {
		durationMs.value = result.durationMs
		const pairs = result.peaks.length / 2
		maxZoom.value = Math.max(20, Math.round((pairs / (result.durationMs / 1000)) * 3))
		const max = Float32Array.from({ length: pairs }, (_, i) => result.peaks[2 * i + 1] / 127)
		const min = Float32Array.from({ length: pairs }, (_, i) => result.peaks[2 * i] / 127)
		buildWave([max, min], result.durationMs / 1000, true)
	} else {
		buildWave([new Float32Array(2)], durationMs.value / 1000)
	}
}

function mediaDuration() {
	return new Promise((resolve) => {
		if (Number.isFinite(audio.duration) && audio.duration > 0) {
			resolve(audio.duration)
			return
		}
		audio.addEventListener('loadedmetadata', () => resolve(audio.duration || 0), { once: true })
		audio.addEventListener('error', () => resolve(0), { once: true })
	})
}

// Real peaks are normalized so quiet recordings still fill the height; the flat placeholder is not.
function buildWave(peaks, durationSeconds, normalize = false) {
	const style = getComputedStyle(document.documentElement)
	ws = WaveSurfer.create({
		container: waveEl.value,
		media: audio,
		peaks,
		duration: durationSeconds,
		height: 140,
		normalize,
		waveColor: '#5d6675',
		progressColor: style.getPropertyValue('--color-primary-element').trim() || '#0082c9',
		cursorColor: '#ffffff',
		cursorWidth: 2,
		minPxPerSec: zoom.value,
		autoScroll: true,
		autoCenter: false,
		dragToSeek: false,
	})
	// Time labels that follow zoom and scroll.
	ws.registerPlugin(TimelinePlugin.create({
		height: 18,
		style: { color: '#9aa3af', fontSize: '11px' },
	}))
	ws.registerPlugin(MinimapPlugin.create({
		container: miniEl.value,
		height: 32,
		normalize,
		waveColor: '#5d6675',
		progressColor: '#8fb8d6',
		cursorColor: '#ffffff',
		cursorWidth: 1,
		overlayColor: 'rgba(255, 255, 255, 0.22)',
		// Tap or drag along the strip to move the playhead there; the zoomed waveform scrolls to follow.
		dragToSeek: true,
	}))
	regions = ws.registerPlugin(RegionsPlugin.create())
	regions.enableDragSelection({ color: 'rgba(0, 130, 201, 0.22)' })

	regions.on('region-created', (region) => {
		if (pinchGuard && region !== selRegion) {
			region.remove()
			return
		}
		if (selRegion && selRegion !== region) {
			selRegion.remove()
		}
		selRegion = region
		updateSelection(region)
	})
	regions.on('region-updated', updateSelection)
	// With a range selected, a click or tap on the waveform outside it goes to the start of the range rather
	// than where it landed. wavesurfer has already seeked; play/pause is left as it was. (The overview strip,
	// skip buttons and keys still move freely.)
	ws.on('click', (relativeX) => {
		const ms = relativeX * durationMs.value
		if (selection.value && (ms < selection.value.startMs || ms > selection.value.endMs)) {
			ws.setTime(selection.value.startMs / 1000)
		}
	})
	// region-out also fires when the playhead is moved out of the range (a click or tap elsewhere), even while
	// paused. Act only when playback runs off the end of the range: start it again with Loop on, stop at the
	// end with Loop off. A paused recording stays paused and a jump elsewhere while playing isn't pulled back.
	regions.on('region-out', (region) => {
		if (region !== selRegion || !ws.isPlaying() || Math.abs(ws.getCurrentTime() - region.end) >= 0.5) {
			return
		}
		if (loop.value) {
			region.play()
		} else {
			ws.pause()
			ws.setTime(region.end)
		}
	})

	ws.on('timeupdate', (s) => {
		currentMs.value = Math.round(s * 1000)
	})
	ws.on('play', () => {
		playing.value = true
		pauseVoiceNotes()
	})
	ws.on('pause', () => {
		playing.value = false
	})
	ws.on('finish', () => {
		playing.value = false
	})
	ws.on('ready', () => {
		drawLanes()
	})
	ws.on('zoom', drawLanes)
	ws.on('redraw', syncLanes)
	ws.on('scroll', syncLanes)
}

function updateSelection(region) {
	selection.value = {
		startMs: Math.round(region.start * 1000),
		endMs: Math.round(region.end * 1000),
	}
}

// A small × in the top right corner of a selection that isn't a saved note's range clears it. The region lives
// in wavesurfer's shadow DOM, out of reach of this component's CSS, so the button is styled inline.
let clearButton = null

function makeClearButton() {
	const button = document.createElement('button')
	button.type = 'button'
	button.textContent = '×'
	button.title = t('audioannotations', 'Clear selection')
	button.setAttribute('aria-label', t('audioannotations', 'Clear selection'))
	Object.assign(button.style, {
		position: 'absolute',
		top: '2px',
		right: '2px',
		zIndex: '5',
		width: '24px',
		height: '24px',
		margin: '0',
		padding: '0',
		border: '0',
		borderRadius: '12px',
		background: 'rgba(0, 0, 0, 0.7)',
		color: '#ffffff',
		font: '18px/24px sans-serif',
		textAlign: 'center',
		cursor: 'pointer',
	})
	// Keep the press from starting a region drag or a seek underneath.
	for (const type of ['pointerdown', 'mousedown', 'touchstart']) {
		button.addEventListener(type, (event) => event.stopPropagation())
	}
	button.addEventListener('click', (event) => {
		event.stopPropagation()
		clearSelection()
	})
	return button
}

function updateClearButton() {
	if (!selRegion || !selection.value) {
		return
	}
	const { startMs, endMs } = selection.value
	const saved = notes.value.some((note) => !isGeneral(note) && note.startMs === startMs && note.endMs === endMs)
	if (saved) {
		selRegion.setContent(undefined)
	} else if (selRegion.content !== clearButton || !clearButton?.isConnected) {
		clearButton ??= makeClearButton()
		selRegion.setContent(clearButton)
	}
}

watch([selection, notes], updateClearButton, { deep: true })

// From the sidebar: the note's id (older payloads only carry its range).
function onNoteSelected(payload) {
	if (payload?.fileId !== props.fileId) {
		return
	}
	const note = notes.value.find((n) => n.id === payload.id)
		?? notes.value.find((n) => n.startMs === payload.startMs && n.endMs === payload.endMs)
	if (note && !isGeneral(note)) {
		toggleNote(note)
	}
}

// One note is selected at a time, tracked by id: notes can share a range, and a general comment has none.
// A timed note stays selected only while the waveform selection is still its range, so dragging the range
// or picking a new one deselects it.
const selectedNoteId = ref(null)
const listOpen = ref(false)
const focusedNote = computed(() => notes.value.find((note) => isSelected(note)) ?? null)
const shownNotes = computed(() => focusedNote.value ? [focusedNote.value] : notes.value)

// While a note is selected the list is held open on it; its summary line then means "show all".
function onSummaryClick(event) {
	if (focusedNote.value) {
		event.preventDefault()
		listOpen.value = true
		toggleNote(focusedNote.value)
	}
}

function onListToggle(event) {
	if (!focusedNote.value) {
		listOpen.value = event.target.open
	}
}
function isSelected(note) {
	return note.id === selectedNoteId.value
		&& (isGeneral(note) || (selection.value?.startMs === note.startMs && selection.value?.endMs === note.endMs))
}

// Clicking the selected note deselects it and leaves the playhead where it is. A general comment just opens
// in the list; picking one drops any range selection.
function toggleNote(note) {
	if (isSelected(note)) {
		selectedNoteId.value = null
		if (!isGeneral(note)) {
			clearSelection()
		}
		return
	}
	selectedNoteId.value = note.id
	if (isGeneral(note)) {
		clearSelection()
	} else {
		selectRange(note.startMs, note.endMs)
	}
}

// Select a range and move to its start. Playback state is left alone: paused stays paused, playing carries on from there.
function selectRange(startMs, endMs) {
	if (!regions) {
		return
	}
	markedStartMs.value = null
	// region-created replaces any previous selection
	regions.addRegion({
		start: startMs / 1000,
		end: endMs / 1000,
		color: 'rgba(0, 130, 201, 0.22)',
		drag: true,
		resize: true,
	})
	ws.setTime(startMs / 1000)
}

const nowMs = () => Math.round((ws?.getCurrentTime() ?? 0) * 1000)

// With a selection, move its start edge; without one, remember where the range begins.
function markStart() {
	if (selection.value && selRegion) {
		const end = Math.max(selection.value.endMs, nowMs())
		selRegion.setOptions({ start: nowMs() / 1000, end: end / 1000 })
		updateSelection(selRegion)
	} else {
		markedStartMs.value = nowMs()
	}
}

function markEnd() {
	const end = nowMs()
	if (selection.value && selRegion) {
		const start = Math.min(selection.value.startMs, end)
		selRegion.setOptions({ start: start / 1000, end: end / 1000 })
		updateSelection(selRegion)
	} else if (markedStartMs.value !== null && end > markedStartMs.value) {
		const start = markedStartMs.value
		const wasPlaying = playing.value
		selectRange(start, end)
		ws.setTime(end / 1000)
		if (wasPlaying) {
			ws.play()
		}
	}
}

// Shortcuts while focus is in the player but not in a text field or on a control (Space and Enter must
// still press a focused button). Handled keys don't reach the Viewer.
function onKey(e) {
	if (e.target.closest('input, textarea, select, audio, button, a, [contenteditable]') || e.metaKey || e.ctrlKey || e.altKey) {
		return
	}
	const actions = {
		' ': togglePlay,
		'[': markStart,
		']': markEnd,
		l: () => { loop.value = !loop.value },
		ArrowLeft: () => skip(-5),
		ArrowRight: () => skip(5),
	}
	const action = actions[e.key]
	if (action) {
		e.preventDefault()
		e.stopPropagation()
		action()
	}
}

function clearSelection() {
	selRegion?.remove()
	selRegion = null
	selection.value = null
}

// Keep the lane strip the same width as the (zoomed) waveform and scrolled to the same place.
function syncLanes() {
	if (!ws || !laneEl.value) {
		return
	}
	const wrapper = ws.getWrapper()
	laneEl.value.style.width = `${wrapper.scrollWidth}px`
	laneEl.value.style.transform = `translateX(${-(wrapper.parentElement?.scrollLeft ?? 0)}px)`
}

// Note bars stacked in lanes in a strip above the waveform.
function drawLanes() {
	if (!ws || !laneEl.value || durationMs.value <= 0) {
		return
	}
	const laneLayer = laneEl.value
	laneLayer.replaceChildren()
	syncLanes()
	const { lanes } = assignLanes(timedNotes.value)
	for (const note of timedNotes.value) {
		const bar = document.createElement('button')
		const left = (note.startMs / durationMs.value) * 100
		const width = Math.max(((note.endMs - note.startMs) / durationMs.value) * 100, 0.15)
		bar.type = 'button'
		const what = note.kind === 'voice' ? t('audioannotations', 'Voice note, {length}', { length: fmt(note.voiceDurationMs ?? 0) }) + '\n' : ''
		bar.title = `${note.owner.displayName} · ${fmt(note.startMs)}–${fmt(note.endMs)}\n${what}${note.body ?? ''}`
		bar.setAttribute('aria-label', t('audioannotations', 'Note by {name}, {start} to {end}', {
			name: note.owner.displayName,
			start: fmt(note.startMs),
			end: fmt(note.endMs),
		}))
		Object.assign(bar.style, {
			position: 'absolute',
			left: `${left}%`,
			width: `max(${width}%, 6px)`,
			top: `${2 + lanes.get(note.id) * LANE_HEIGHT}px`,
			height: `${LANE_HEIGHT - 3}px`,
			padding: '0',
			margin: '0',
			minHeight: '0',
			border: '0',
			borderRadius: '3px',
			background: NOTE_COLORS[note.color] ?? NOTE_COLORS.blue,
			opacity: note.resolved ? '0.45' : '1',
			cursor: 'pointer',
		})
		bar.addEventListener('click', (e) => {
			e.stopPropagation()
			toggleNote(note)
		})
		laneLayer.appendChild(bar)
	}
}

// With a range selected, Play from anywhere outside it, or from its very end (where playback stops with
// Loop off), starts at the beginning of the range.
function togglePlay() {
	if (ws && !ws.isPlaying() && selection.value) {
		const nowMs = ws.getCurrentTime() * 1000
		if (nowMs < selection.value.startMs || nowMs >= selection.value.endMs - 50) {
			ws.setTime(selection.value.startMs / 1000)
		}
	}
	ws?.playPause()
}

function skip(seconds) {
	if (ws) {
		ws.setTime(Math.min(Math.max(ws.getCurrentTime() + seconds, 0), ws.getDuration()))
	}
}

function applyZoom() {
	ws?.zoom(zoom.value)
}

// Zoom to targetPxPerSec while keeping the moment under clientX in place. zoom is wavesurfer's minPxPerSec,
// where anything at or below "fits the width" means fit (0).
function zoomAround(targetPxPerSec, clientX) {
	const scroller = ws.getWrapper().parentElement
	const fit = scroller.clientWidth / (durationMs.value / 1000)
	const x = clientX - scroller.getBoundingClientRect().left
	const atSec = (scroller.scrollLeft + x) / Math.max(zoom.value, fit)
	const target = Math.min(maxZoom.value, targetPxPerSec)
	zoom.value = target <= fit ? 0 : Math.round(target)
	ws.zoom(zoom.value)
	scroller.scrollLeft = atSec * Math.max(zoom.value, fit) - x
}

// Desktop: Ctrl/⌘ + scroll wheel, or a trackpad pinch (which browsers report the same way), zooms the waveform.
function onWheelZoom(e) {
	if (!(e.ctrlKey || e.metaKey) || !ws || durationMs.value <= 0) {
		return
	}
	e.preventDefault()
	const fit = ws.getWrapper().parentElement.clientWidth / (durationMs.value / 1000)
	zoomAround(Math.max(zoom.value, fit) * Math.exp(-e.deltaY / 200), e.clientX)
}

// Two-finger pinch on the waveform zooms around the point between the fingers. zoom is wavesurfer's
// minPxPerSec, where anything below "fits the width" (and 0) means fit.
const fingerGap = (touches) => Math.hypot(touches[0].clientX - touches[1].clientX, touches[0].clientY - touches[1].clientY)

function onPinchStart(e) {
	if (e.touches.length !== 2 || !ws || durationMs.value <= 0) {
		return
	}
	clearTimeout(pinchGuardTimer)
	pinchGuard = true
	// The first finger may have started a drag-selection; end it. The selection is put back when the pinch ends.
	document.dispatchEvent(new PointerEvent('pointerup', { bubbles: true }))
	const fitPxPerSec = ws.getWrapper().parentElement.clientWidth / (durationMs.value / 1000)
	pinch = {
		gap: fingerGap(e.touches),
		pxPerSec: Math.max(zoom.value, fitPxPerSec),
		selection: pinch?.selection ?? selection.value,
		lastAt: 0,
	}
}

function onPinchMove(e) {
	if (!pinch || e.touches.length !== 2) {
		return
	}
	e.preventDefault()
	// Re-rendering a zoomed waveform is not free; about 30 updates a second is smooth enough.
	if (e.timeStamp - pinch.lastAt < 32) {
		return
	}
	pinch.lastAt = e.timeStamp
	const [a, b] = e.touches
	zoomAround(pinch.pxPerSec * fingerGap(e.touches) / pinch.gap, (a.clientX + b.clientX) / 2)
}

function onPinchEnd(e) {
	if (!pinch || e.touches.length > 0) {
		return
	}
	const before = pinch.selection
	pinch = null
	// Pointer events for the lifted fingers can arrive after the last touchend; restore once they have.
	pinchGuardTimer = setTimeout(() => {
		pinchGuard = false
		const now = selection.value
		if (before?.startMs === now?.startMs && before?.endMs === now?.endMs) {
			return
		}
		if (before) {
			regions.addRegion({ start: before.startMs / 1000, end: before.endMs / 1000, color: 'rgba(0, 130, 201, 0.22)', drag: true, resize: true })
		} else {
			clearSelection()
		}
	}, 300)
}

function onRecording(active) {
	if (!ws) {
		return
	}
	if (active && !duck.value) {
		ws.pause()
	}
	ws.setVolume(active && duck.value ? 0.2 : 1)
}

// Only one thing plays at a time: a voice note pauses the recording and any other voice note, and vice versa.
function pauseVoiceNotes(except = null) {
	rootEl.value?.querySelectorAll('audio.anp__voice').forEach((el) => {
		if (el !== except) {
			el.pause()
		}
	})
}

function onVoicePlay(e) {
	ws?.pause()
	pauseVoiceNotes(e.target)
}

function onNoteSaved(note) {
	notes.value = sortNotes([...notes.value, note])
	emit(NOTES_CHANGED, { fileId: props.fileId, source: 'player' })
}

// The first character typed pins the note's time; emptying the box lets it follow the playhead again.
watch(draft, (text) => {
	if (text === '') {
		draftAtMs.value = null
	} else if (draftAtMs.value === null) {
		draftAtMs.value = currentMs.value
	}
})

// A voice note needs a fixed range while recording, so without one select the 5 s at the playhead
// (a general comment, at 0:00, needs none).
function openVoice() {
	if (!selection.value && !target.value.general) {
		const { startMs, endMs } = target.value
		selectRange(startMs, endMs)
	}
	voiceOpen.value = true
}

function onVoiceSaved(note) {
	voiceOpen.value = false
	onNoteSaved(note)
}

async function addNote() {
	if (draft.value.trim() === '') {
		return
	}
	saving.value = true
	error.value = ''
	try {
		const note = await createNote(props.fileId, {
			startMs: target.value.startMs,
			endMs: target.value.endMs,
			body: draft.value,
		})
		notes.value = sortNotes([...notes.value, note])
		draft.value = ''
		emit(NOTES_CHANGED, { fileId: props.fileId, source: 'player' })
	} catch (e) {
		error.value = errorMessage(e, t('audioannotations', 'Could not save the note. Check your connection and try again.'))
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.anp {
	width: 100%;
	max-width: 1100px;
	min-width: 0;
	box-sizing: border-box;
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	color: #e8eaee;
	background: #1d2026;
	border-radius: var(--border-radius-large, 12px);
	/* The player is always dark, but Nextcloud's buttons, switches and fields inside it take their colours
	   from the page theme: in the light theme they drew near-black text on this background. Give everything
	   inside the player Nextcloud's dark-theme neutrals (from theming's dark.css); the primary colour stays
	   the instance's own. color-scheme makes native controls (select, audio, scrollbars) dark too. */
	color-scheme: dark;
	--color-main-background: #171717;
	--color-main-background-rgb: 23, 23, 23;
	--color-background-hover: #2a2f37;
	--color-background-dark: #292929;
	--color-background-darker: #3b3b3b;
	--color-placeholder-light: #313131;
	--color-placeholder-dark: #4a4a4a;
	--color-main-text: #ebebeb;
	--color-text-maxcontrast: #a3abb6;
	--color-text-maxcontrast-default: #a3abb6;
	--color-text-error: #ff6f6f;
	--color-text-success: #49bb37;
	--color-border: #3b414b;
	--color-border-dark: #4a515c;
	--color-border-maxcontrast: #7d7d7d;
	--color-element-error: #ff5050;
	--color-element-success: #40a330;
	--color-element-warning: #ffcc00;
	--color-error: #552121;
	--color-error-hover: #7a2f2f;
	--color-error-text: #ffcccc;
	--color-warning: #3d3010;
	--color-warning-hover: #65501b;
	--color-warning-text: #ffeec5;
	--color-success: #11321a;
	--color-success-hover: #1e582e;
	--color-success-text: #d5f2dc;
	--color-info: #003553;
	--color-info-hover: #005686;
	--color-info-text: #00aeff;
}

.anp__play-label {
	display: inline-grid;
}

.anp__play-label > span {
	grid-area: 1 / 1;
	text-align: center;
}

.anp__play-off {
	visibility: hidden;
}

.anp__who {
	display: inline-flex;
	flex: 0 0 auto;
	align-self: center;
}

.anp__count {
	margin-inline-start: auto;
	white-space: nowrap;
}

.anp__meta,
.anp__status {
	color: #9aa3af;
	font-size: 0.9em;
}

.anp__stage {
	background: #262a32;
	border-radius: 8px;
	padding: 4px 6px 6px;
}

.anp__minimap {
	margin-top: 6px;
	border-radius: 6px;
	background: #1d2026;
}

.anp__lanes {
	position: relative;
	overflow: hidden;
}

.anp__lanes-track {
	position: absolute;
	inset: 0 auto 0 0;
}

.anp__transport {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
	align-items: center;
	gap: 8px 12px;
}

.anp__transport-side {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 12px;
	min-width: 0;
}

.anp__transport-side--end {
	justify-content: flex-end;
}

.anp__time {
	font-variant-numeric: tabular-nums;
	min-width: 11ch;
}

.anp__zoom {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}
/* Phones and tablets zoom by pinching, so the slider would only take room. */
@media (pointer: coarse) {
	.anp__zoom {
		display: none;
	}
}

.anp__dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: #0082c9;
}

.anp:focus {
	outline: none;
}

.anp:focus-visible {
	outline: 2px solid #4fb3ea;
}

.anp__warn {
	background: rgba(242, 165, 22, 0.15);
	border: 1px solid rgba(242, 165, 22, 0.5);
	border-radius: 8px;
	padding: 6px 10px;
}

.anp__marks {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 8px;
}

.anp__notes summary {
	cursor: pointer;
	color: #9aa3af;
}

.anp__notes ul {
	display: flex;
	flex-direction: column;
	max-height: 220px;
	overflow-y: auto;
	margin-top: 6px;
}

.anp__note {
	all: unset;
	box-sizing: border-box;
	width: 100%;
	display: flex;
	align-items: baseline;
	gap: 8px;
	padding: 6px 8px;
	border-radius: 6px;
	cursor: pointer;
	min-height: 36px;
}

.anp__note:hover,
.anp__note:focus-visible {
	background: #2f343d;
}

.anp__note--selected {
	background: rgba(0, 130, 201, 0.22);
	box-shadow: inset 3px 0 0 #0082c9;
}

.anp__note--resolved {
	opacity: 0.55;
}

.anp__note-time {
	color: #9aa3af;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.anp__voice {
	display: block;
	width: calc(100% - 16px);
	height: 34px;
	margin: 0 8px 6px;
}

.anp__note-text {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
/* The selected note shows its whole text on its own lines, with its replies below. */
.anp__note--selected {
	flex-wrap: wrap;
}

.anp__note--selected .anp__note-text {
	flex-basis: 100%;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.anp__notes .anp__replies {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-height: none;
	overflow: visible;
	margin: 2px 8px 6px 20px;
	padding-inline-start: 10px;
	border-inline-start: 2px solid #3b414b;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.anp__notes .anp__replies > li {
	display: flex;
	align-items: flex-start;
	gap: 6px;
}

.anp__dock {
	position: sticky;
	bottom: 0;
	z-index: 2;
	margin: 0 -24px -24px;
	padding: 10px 24px 16px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	background: #181b20;
	border-top: 1px solid #2a2f37;
	border-radius: 0 0 var(--border-radius-large, 12px) var(--border-radius-large, 12px);
}

.anp__dock-target {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: 32px;
	color: #9aa3af;
	font-size: 0.9em;
	font-variant-numeric: tabular-nums;
}

.anp__dock-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.anp__dock-input {
	flex: 1;
	min-width: 0;
}

.anp__dock-input input {
	box-sizing: border-box;
	width: 100%;
	height: 48px;
	margin: 0;
	padding: 0 16px;
	border: 1px solid #3b414b;
	border-radius: 24px;
	background: #22262d;
	color: #eef1f4;
	font-size: 15px;
}

.anp__dock-input input::placeholder {
	color: #8d96a3;
}

.anp__dock-input input:focus-visible {
	outline: 2px solid #4fb3ea;
	outline-offset: 1px;
}

.anp__dock-btn {
	flex: 0 0 48px;
	width: 48px;
	height: 48px;
	margin: 0;
	padding: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	border: 1px solid #3b414b;
	border-radius: 24px;
	background: #22262d;
	color: #eef1f4;
	cursor: pointer;
}

.anp__dock-btn--send {
	border: 0;
	background: #0082c9;
	color: #ffffff;
}

.anp__dock-btn:disabled {
	opacity: 0.45;
	cursor: default;
}

.anp__dock-btn:focus-visible {
	outline: 2px solid #4fb3ea;
	outline-offset: 2px;
}
@media (max-width: 600px) {
	.anp {
		padding: 12px;
		border-radius: 0;
	}
	.anp__marks {
		display: grid;
		grid-template-columns: 1fr 1fr auto;
	}
	.anp__marks :deep(button) {
		min-height: 44px;
	}
	.anp__dock {
		margin: 0 -12px -12px;
		padding: 10px 12px 14px;
		border-radius: 0;
	}
}
</style>
