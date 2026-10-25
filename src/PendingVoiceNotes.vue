<!--
  - SPDX-FileCopyrightText: 2026 Corbin Keener
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcNoteCard v-if="pending.length > 0" type="warning">
		<p>{{ n('audioannotations', '%n voice note recorded here was not uploaded.', '%n voice notes recorded here were not uploaded.', pending.length) }}</p>
		<ul class="anpv">
			<li v-for="item in pending" :key="item.id" class="anpv__item">
				<span>{{ formatTime(item.startMs) }}–{{ formatTime(item.endMs) }} · {{ formatTime(item.durationMs) }}</span>
				<NcButton variant="primary" :disabled="busy === item.id" @click="upload(item)">
					{{ t('audioannotations', 'Upload') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="busy === item.id" @click="discard(item)">
					{{ t('audioannotations', 'Discard') }}
				</NcButton>
			</li>
		</ul>
		<p v-if="error" role="alert">
			{{ error }}
		</p>
	</NcNoteCard>
</template>

<script setup>
import { n, t } from '@nextcloud/l10n'
import { onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { errorMessage } from './api.js'
import { formatTime } from './time.js'
import { discardPending, listPending, saveVoiceNote } from './voice.js'

const props = defineProps({
	fileId: { type: Number, default: null },
})
const emit = defineEmits(['uploaded'])

const pending = ref([])
const busy = ref(null)
const error = ref('')

async function refresh() {
	pending.value = props.fileId ? await listPending(props.fileId) : []
}

async function upload(item) {
	busy.value = item.id
	error.value = ''
	try {
		const note = await saveVoiceNote(item)
		emit('uploaded', note)
	} catch (e) {
		error.value = errorMessage(e, t('audioannotations', 'Upload failed again. Check your connection.'))
	} finally {
		busy.value = null
		await refresh()
	}
}

async function discard(item) {
	await discardPending(item.id)
	await refresh()
}

onMounted(refresh)
watch(() => props.fileId, refresh)
defineExpose({ refresh })
</script>

<style scoped>
.anpv {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 4px;
}

.anpv__item {
	display: flex;
	align-items: center;
	gap: 8px;
	font-variant-numeric: tabular-nums;
}
</style>
