<!--
  - SPDX-FileCopyrightText: 2026 Corbin Keener
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<li class="ani" :class="{ 'ani--resolved': note.resolved }">
		<div class="ani__head">
			<span class="ani__dot" :style="{ background: NOTE_COLORS[note.color] ?? NOTE_COLORS.blue }" />
			<span class="ani__who" :title="note.owner.displayName">
				<NcAvatar
					:user="note.owner.uid"
					:displayName="note.owner.displayName"
					:size="22"
					hideStatus
					disableMenu
					disableTooltip
					aria-hidden="true" />
				<span class="hidden-visually">{{ note.owner.displayName }}</span>
			</span>
			<span v-if="isGeneral(note)" class="ani__badge">{{ t('audioannotations', 'General') }}</span>
			<button
				v-else
				type="button"
				class="ani__time"
				:title="t('audioannotations', 'Go to this range')"
				@click="emit('select', note)">
				{{ formatTime(note.startMs) }}–{{ formatTime(note.endMs) }}
			</button>
			<span v-if="note.visibility === 'private'" class="ani__badge">{{ t('audioannotations', 'Private') }}</span>
			<span v-if="note.resolved" class="ani__badge">{{ t('audioannotations', 'Resolved') }}</span>
			<span v-if="note.stale" class="ani__badge ani__badge--warn" :title="t('audioannotations', 'The recording changed length after this note was made, so the times may be off.')">
				{{ t('audioannotations', 'Times may be off') }}
			</span>
		</div>

		<div v-if="note.kind === 'voice'" class="ani__voice">
			<audio
				v-if="note.voiceUrl"
				:src="note.voiceUrl"
				controls
				preload="metadata"
				:aria-label="t('audioannotations', 'Voice note by {name}', { name: note.owner.displayName })" />
			<span class="ani__muted">{{ formatTime(note.voiceDurationMs ?? 0) }}</span>
		</div>

		<form
			v-if="editing"
			ref="editForm"
			class="ani__edit"
			@submit.prevent="saveEdit">
			<NcTextArea v-model="editText" :label="note.kind === 'voice' ? t('audioannotations', 'Caption') : t('audioannotations', 'Note')" rows="3" />
			<div class="ani__actions">
				<NcButton type="submit" variant="primary" :disabled="busy || (note.kind !== 'voice' && editText.trim() === '')">
					{{ t('audioannotations', 'Save') }}
				</NcButton>
				<NcButton variant="tertiary" @click="editing = false">
					{{ t('audioannotations', 'Cancel') }}
				</NcButton>
			</div>
		</form>
		<p v-else-if="note.body" class="ani__body">
			{{ note.body }}
		</p>

		<ul v-if="note.replies.length > 0" class="ani__replies">
			<li v-for="reply in note.replies" :key="reply.id" class="ani__reply">
				<span class="ani__who" :title="reply.owner.displayName">
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
				<span class="ani__body">{{ reply.body }}</span>
				<button
					v-if="reply.canDelete"
					type="button"
					class="ani__link"
					:aria-label="t('audioannotations', 'Delete reply by {name}', { name: reply.owner.displayName })"
					@click="removeReply(reply)">
					{{ t('audioannotations', 'Delete') }}
				</button>
			</li>
		</ul>

		<form
			v-if="replying"
			ref="replyForm"
			class="ani__edit"
			@submit.prevent="sendReply">
			<NcTextField v-model="replyText" :label="t('audioannotations', 'Reply')" />
			<div class="ani__actions">
				<NcButton type="submit" variant="primary" :disabled="busy || replyText.trim() === ''">
					{{ t('audioannotations', 'Send') }}
				</NcButton>
				<NcButton variant="tertiary" @click="replying = false">
					{{ t('audioannotations', 'Cancel') }}
				</NcButton>
			</div>
		</form>

		<div v-if="!editing && !replying" class="ani__links">
			<button type="button" class="ani__link" @click="startReply">
				{{ t('audioannotations', 'Reply') }}
			</button>
			<button
				v-if="note.canEdit"
				type="button"
				class="ani__link"
				@click="startEdit">
				{{ t('audioannotations', 'Edit') }}
			</button>
			<button
				v-if="note.canResolve"
				type="button"
				class="ani__link"
				:disabled="busy"
				@click="toggleResolved">
				{{ note.resolved ? t('audioannotations', 'Reopen') : t('audioannotations', 'Resolve') }}
			</button>
			<button
				v-if="note.canDelete"
				type="button"
				class="ani__link ani__link--danger"
				:disabled="busy"
				@click="emit('delete', note)">
				{{ t('audioannotations', 'Delete') }}
			</button>
		</div>

		<p v-if="error" class="ani__error" role="alert">
			{{ error }}
		</p>
	</li>
</template>

<script setup>
import { t } from '@nextcloud/l10n'
import { nextTick, ref } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { addReply, deleteReply, errorMessage, updateNote } from './api.js'
import { isGeneral, NOTE_COLORS } from './notes.js'
import { formatTime } from './time.js'

const props = defineProps({
	note: { type: Object, required: true },
})
// changed(note) after edits/replies/resolve; delete(note) is confirmed by the parent; select(note) jumps to it.
const emit = defineEmits(['changed', 'delete', 'select'])

const editing = ref(false)
const editText = ref('')
const replying = ref(false)
const replyText = ref('')
const busy = ref(false)
const editForm = ref(null)
const replyForm = ref(null)
const error = ref('')

async function run(action, fallback) {
	busy.value = true
	error.value = ''
	try {
		await action()
	} catch (e) {
		error.value = errorMessage(e, fallback)
	} finally {
		busy.value = false
	}
}

// Put the cursor in the field that just opened, so typing never lands on the Files page behind it.
async function focusIn(form) {
	await nextTick()
	form.value?.querySelector('textarea, input')?.focus()
}

function startEdit() {
	editText.value = props.note.body ?? ''
	editing.value = true
	focusIn(editForm)
}

function startReply() {
	replying.value = true
	focusIn(replyForm)
}

function saveEdit() {
	return run(async () => {
		emit('changed', await updateNote(props.note.id, { body: editText.value }))
		editing.value = false
	}, t('audioannotations', 'Could not save your changes.'))
}

function toggleResolved() {
	return run(async () => {
		emit('changed', await updateNote(props.note.id, { resolved: !props.note.resolved }))
	}, t('audioannotations', 'Could not update the note.'))
}

function sendReply() {
	return run(async () => {
		const reply = await addReply(props.note.id, replyText.value)
		emit('changed', { ...props.note, replies: [...props.note.replies, reply] })
		replyText.value = ''
		replying.value = false
	}, t('audioannotations', 'Could not send the reply.'))
}

function removeReply(reply) {
	return run(async () => {
		await deleteReply(reply.id)
		emit('changed', { ...props.note, replies: props.note.replies.filter((r) => r.id !== reply.id) })
	}, t('audioannotations', 'Could not delete the reply.'))
}
</script>

<style scoped>
.ani {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px 12px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.ani--resolved {
	opacity: 0.7;
}

.ani__head {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 2px 8px;
}

.ani__who {
	display: inline-flex;
	flex: 0 0 auto;
}

.ani__badge {
	white-space: nowrap;
}

.ani__dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	flex: 0 0 10px;
}

.ani__time {
	all: unset;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.ani__time:hover,
.ani__time:focus-visible {
	color: var(--color-main-text);
	text-decoration: underline;
}

.ani__badge {
	font-size: 0.85em;
	padding: 0 6px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
}

.ani__badge--warn {
	background: var(--color-warning);
	color: var(--color-warning-text, #000);
}

.ani__voice {
	display: flex;
	align-items: center;
	gap: 8px;
}

.ani__voice audio {
	height: 34px;
	flex: 1;
	min-width: 0;
}

.ani__muted {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.ani__body {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.ani__replies {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-inline-start: 10px;
	border-inline-start: 2px solid var(--color-border);
}

.ani__reply {
	display: flex;
	align-items: flex-start;
	gap: 6px;
}

.ani__links,
.ani__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 12px;
}

.ani__link {
	all: unset;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.ani__link:hover,
.ani__link:focus-visible {
	color: var(--color-main-text);
	text-decoration: underline;
}

.ani__link--danger:hover,
.ani__link--danger:focus-visible {
	color: var(--color-error-text);
}

.ani__link:disabled {
	opacity: 0.5;
	cursor: default;
}

.ani__edit {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.ani__error {
	color: var(--color-error-text);
}
</style>
