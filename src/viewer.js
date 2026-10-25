/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Viewer integration. All reliance on Viewer internals lives in this file.
//
// The Viewer (Vue 2) gives each mime to the first handler that claims it, and its built-in
// "audios" handler always registers first. So before the Viewer wires up its handlers on
// DOMContentLoaded, we take "audios" out of OCA.Viewer.availableHandlers and register ours.
// If a Viewer update changes this, the built-in player simply stays in charge. Public share pages
// (nobody logged in) also keep it: notes need an account.

import { generateRemoteUrl } from '@nextcloud/router'
import { createApp } from 'vue'
import NotesPlayer from './NotesPlayer.vue'
import logger from './logger.js'

import './viewer.css'

const AUDIO_MIMES = [
	'audio/aac',
	'audio/aacp',
	'audio/flac',
	'audio/mp4',
	'audio/mpeg',
	'audio/ogg',
	'audio/vorbis',
	'audio/wav',
	'audio/webm',
]

// Written as a plain Vue 2 options object (the Viewer's Vue). It mounts our Vue 3 player inside.
// The Viewer adds its Mime mixin, which supplies active, fileid, basename, filename, src and doneLoading().
const ViewerComponent = {
	name: 'AudioAnnotationsPlayer',
	data() {
		return { playerApp: null }
	},
	watch: {
		active() {
			this.syncPlayer()
		},
		fileid() {
			this.unmountPlayer()
			this.syncPlayer()
		},
	},
	mounted() {
		this.syncPlayer()
		this.doneLoading()
	},
	beforeDestroy() {
		this.unmountPlayer()
	},
	methods: {
		// Only the visible file gets a live player; preloaded neighbours stay empty and silent.
		syncPlayer() {
			if (this.active && !this.playerApp) {
				const el = document.createElement('div')
				el.className = 'audioannotations-viewer__mount'
				this.$el.appendChild(el)
				const uid = window.OC?.getCurrentUser?.()?.uid
				this.playerApp = createApp(NotesPlayer, {
					fileId: Number(this.fileid),
					src: this.src ?? generateRemoteUrl(`dav/files/${uid}`) + this.filename.split('/').map(encodeURIComponent).join('/'),
				})
				this.playerApp.mount(el)
			} else if (!this.active) {
				this.unmountPlayer()
			}
		},
		unmountPlayer() {
			this.playerApp?.unmount()
			this.playerApp = null
			this.$el.replaceChildren()
		},
	},
	render(h) {
		return h('div', { class: 'audioannotations-viewer' })
	},
}

function install() {
	const viewer = window.OCA?.Viewer
	if (!viewer?.registerHandler) {
		logger.warn('Viewer not found; keeping the standard audio player')
		return
	}
	if (!window.OC?.getCurrentUser?.()?.uid) {
		return
	}
	const handlers = viewer.availableHandlers
	const at = handlers.findIndex((handler) => handler.id === 'audios')
	const builtIn = at !== -1 ? handlers.splice(at, 1)[0] : null
	try {
		viewer.registerHandler({
			id: 'audioannotations',
			group: 'media',
			// Every format the built-in player took, so none is left without a player.
			mimes: [...new Set([...AUDIO_MIMES, ...(builtIn?.mimes ?? [])])],
			component: ViewerComponent,
		})
	} catch (e) {
		if (builtIn) {
			handlers.splice(at, 0, builtIn)
		}
		logger.warn('Could not register the player; keeping the standard audio player', { error: e })
	}
}

// Module scripts in the page run before DOMContentLoaded (readyState is already "interactive"),
// which is when the Viewer reads its handler list, so installing now is early enough.
install()
document.addEventListener('DOMContentLoaded', () => {
	if (!window.OCA?.Viewer?.availableHandlers?.some((handler) => handler.id === 'audioannotations')) {
		logger.warn('Viewer handler not registered; the standard audio player is in use')
	}
})
