<!--
  - SPDX-FileCopyrightText: 2026 Corbin Keener
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="anv">
		<VoiceRecorder
			ref="recorderEl"
			:disabled="!rangeOk || saving"
			@started="emit('recording', true)"
			@stopped="emit('recording', false)"
			@recorded="onRecorded"
			@discarded="recording = null" />
		<template v-if="recording">
			<NcTextField v-model="caption" :label="t('audioannotations', 'Caption (optional)')" />
			<div class="anv__row">
				<NcCheckboxRadioSwitch v-model="isPrivate">
					{{ t('audioannotations', 'Only visible to me') }}
				</NcCheckboxRadioSwitch>
				<NcButton variant="primary" :disabled="saving || !rangeOk" @click="save">
					{{ saving ? t('audioannotations', 'Saving… {percent}%', { percent: Math.round(progress * 100) }) : (failed ? t('audioannotations', 'Try again') : t('audioannotations', 'Save voice note')) }}
				</NcButton>
			</div>
		</template>
		<p v-if="!rangeOk" class="anv__hint">
			{{ t('audioannotations', 'Choose a start and end first.') }}
		</p>
		<p v-if="error" class="anv__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script setup>
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import VoiceRecorder from './VoiceRecorder.vue'
import { errorMessage } from './api.js'
import { saveVoiceNote } from './voice.js'

const props = defineProps({
	fileId: { type: Number, required: true },
	startMs: { type: Number, default: null },
	endMs: { type: Number, default: null },
})
const emit = defineEmits(['saved', 'recording'])

const recorderEl = ref(null)
const recording = ref(null)
const caption = ref('')
const isPrivate = ref(false)
const saving = ref(false)
const failed = ref(false)
const progress = ref(0)
const error = ref('')

const rangeOk = computed(() => props.startMs !== null && props.endMs !== null && props.endMs >= props.startMs)

// The id survives retries so a buffered copy is replaced, not duplicated.
let pendingId = null

function onRecorded(result) {
	recording.value = result
	pendingId = `${props.fileId}-${Date.now()}`
	failed.value = false
	error.value = ''
}

async function save() {
	if (!recording.value || !rangeOk.value) {
		return
	}
	saving.value = true
	progress.value = 0
	error.value = ''
	try {
		const note = await saveVoiceNote({
			id: pendingId,
			fileId: props.fileId,
			startMs: props.startMs,
			endMs: props.endMs,
			durationMs: recording.value.durationMs,
			caption: caption.value,
			visibility: isPrivate.value ? 'private' : 'shared',
			blob: recording.value.blob,
			type: recording.value.type,
		}, (fraction) => { progress.value = fraction })
		recording.value = null
		caption.value = ''
		failed.value = false
		recorderEl.value?.reset()
		emit('saved', note)
	} catch (e) {
		failed.value = true
		error.value = errorMessage(e, t('audioannotations', 'The voice note was not uploaded. It is kept in this browser; try again, or reopen the file later to retry.'))
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.anv {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.anv__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	flex-wrap: wrap;
}

.anv__hint {
	color: var(--color-text-maxcontrast, #9aa3af);
	font-size: 0.9em;
}

.anv__error {
	color: var(--color-error-text, #e0334b);
}
</style>
