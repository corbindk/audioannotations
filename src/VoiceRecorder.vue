<!--
  - SPDX-FileCopyrightText: 2026 Corbin Keener
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="anr">
		<p v-if="!supported" class="anr__msg">
			{{ t('audioannotations', 'This browser cannot record audio. Use a current Chrome, Firefox, Edge or Safari, opened over HTTPS.') }}
		</p>

		<template v-else-if="state === 'idle'">
			<NcButton variant="error" :disabled="disabled" @click="start">
				{{ t('audioannotations', 'Record') }}
			</NcButton>
			<span class="anr__msg">{{ t('audioannotations', 'Up to 5 minutes.') }}</span>
		</template>

		<template v-else-if="state === 'recording'">
			<span class="anr__live" aria-hidden="true" />
			<span class="anr__time" aria-live="off">{{ formatTime(elapsedMs) }}</span>
			<span class="anr__meter" aria-hidden="true">
				<span class="anr__level" :style="{ width: `${Math.round(level * 100)}%` }" />
			</span>
			<NcButton variant="primary" @click="stop">
				{{ t('audioannotations', 'Stop') }}
			</NcButton>
		</template>

		<template v-else-if="state === 'recorded'">
			<audio class="anr__preview" :src="previewUrl" controls />
			<span class="anr__time">{{ formatTime(durationMs) }}</span>
			<NcButton variant="tertiary" @click="discard">
				{{ t('audioannotations', 'Discard') }}
			</NcButton>
		</template>

		<p v-if="error" class="anr__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script setup>
import { t } from '@nextcloud/l10n'
import { onBeforeUnmount, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { formatTime } from './time.js'
import { canRecord, MAX_RECORDING_MS, pickMimeType } from './voice.js'

defineProps({
	disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['started', 'stopped', 'recorded', 'discarded'])

const supported = canRecord()
const state = ref('idle')
const elapsedMs = ref(0)
const durationMs = ref(0)
const level = ref(0)
const error = ref('')
const previewUrl = ref('')

let stream = null
let recorder = null
let chunks = []
let startedAt = 0
let timer = null
let audioContext = null
let analyser = null
let meterFrame = null

async function start() {
	error.value = ''
	try {
		stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } })
	} catch (e) {
		error.value = e?.name === 'NotAllowedError'
			? t('audioannotations', 'Microphone access was blocked. Allow it in the browser\'s site settings, then try again.')
			: t('audioannotations', 'No microphone was found.')
		return
	}
	const mimeType = pickMimeType()
	recorder = new MediaRecorder(stream, mimeType ? { mimeType, audioBitsPerSecond: 64000 } : { audioBitsPerSecond: 64000 })
	chunks = []
	recorder.ondataavailable = (e) => e.data.size > 0 && chunks.push(e.data)
	recorder.onstop = finish
	recorder.start(1000)
	startedAt = performance.now()
	state.value = 'recording'
	emit('started')
	timer = setInterval(() => {
		elapsedMs.value = performance.now() - startedAt
		if (elapsedMs.value >= MAX_RECORDING_MS) {
			stop()
		}
	}, 200)
	startMeter()
}

function stop() {
	if (recorder?.state === 'recording') {
		durationMs.value = Math.min(Math.round(performance.now() - startedAt), MAX_RECORDING_MS)
		recorder.stop()
	}
}

// Safari stops recording when the screen locks or the tab is hidden; keep what we have.
function onHidden() {
	if (document.hidden && state.value === 'recording') {
		stop()
		error.value = t('audioannotations', 'Recording stopped because the page was hidden. You can keep or discard what was recorded.')
	}
}
document.addEventListener('visibilitychange', onHidden)

function finish() {
	cleanupInput()
	const type = recorder.mimeType || chunks[0]?.type || 'audio/webm'
	const blob = new Blob(chunks, { type })
	previewUrl.value = URL.createObjectURL(blob)
	state.value = 'recorded'
	emit('stopped')
	emit('recorded', { blob, type, durationMs: durationMs.value })
}

function discard() {
	reset()
	emit('discarded')
}

function reset() {
	if (previewUrl.value) {
		URL.revokeObjectURL(previewUrl.value)
	}
	previewUrl.value = ''
	state.value = 'idle'
	elapsedMs.value = 0
	durationMs.value = 0
	error.value = ''
}

function startMeter() {
	try {
		audioContext = new AudioContext()
		analyser = audioContext.createAnalyser()
		analyser.fftSize = 512
		audioContext.createMediaStreamSource(stream).connect(analyser)
		const data = new Uint8Array(analyser.fftSize)
		const tick = () => {
			analyser.getByteTimeDomainData(data)
			let peak = 0
			for (const v of data) {
				peak = Math.max(peak, Math.abs(v - 128))
			}
			level.value = Math.min(1, peak / 100)
			meterFrame = requestAnimationFrame(tick)
		}
		tick()
	} catch {
		level.value = 0
	}
}

function cleanupInput() {
	clearInterval(timer)
	cancelAnimationFrame(meterFrame)
	audioContext?.close()
	audioContext = null
	stream?.getTracks().forEach((track) => track.stop())
	stream = null
	level.value = 0
}

onBeforeUnmount(() => {
	document.removeEventListener('visibilitychange', onHidden)
	if (recorder?.state === 'recording') {
		recorder.onstop = null
		recorder.stop()
	}
	cleanupInput()
	reset()
})

defineExpose({ reset })
</script>

<style scoped>
.anr {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px 12px;
	min-height: 44px;
}

.anr__msg {
	color: var(--color-text-maxcontrast, #9aa3af);
	font-size: 0.9em;
}

.anr__live {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	background: #e0334b;
	animation: anr-pulse 1.2s ease-in-out infinite;
}
@media (prefers-reduced-motion: reduce) {
	.anr__live {
		animation: none;
	}
}
@keyframes anr-pulse {
	50% { opacity: 0.35; }
}

.anr__time {
	font-variant-numeric: tabular-nums;
	min-width: 5ch;
}

.anr__meter {
	flex: 1 1 120px;
	max-width: 240px;
	height: 8px;
	border-radius: 4px;
	background: rgba(127, 127, 127, 0.25);
	overflow: hidden;
}

.anr__level {
	display: block;
	height: 100%;
	background: #e0334b;
	transition: width 80ms linear;
}

.anr__preview {
	height: 36px;
	max-width: 100%;
}

.anr__error {
	flex-basis: 100%;
	color: var(--color-error-text, #e0334b);
}
</style>
