/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'
import { createApp } from 'vue'
import NotesTab from './NotesTab.vue'

// mdi-note-text-outline
const iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M15,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V9L15,3M19,19H5V5H14V10H19M17,13H7V11H17V13M14,16H7V14H14"/></svg>'

let app = null
let vm = null

function register() {
	if (!window.OCA?.Files?.Sidebar) {
		return
	}
	window.OCA.Files.Sidebar.registerTab(new window.OCA.Files.Sidebar.Tab({
		id: 'audioannotations',
		name: t('audioannotations', 'Annotations'),
		iconSvg,
		enabled(fileInfo) {
			return fileInfo?.type !== 'dir' && (fileInfo?.mimetype ?? '').startsWith('audio/')
		},
		mount(el, fileInfo) {
			app?.unmount()
			app = createApp(NotesTab)
			vm = app.mount(el)
			vm.setFile(fileInfo)
		},
		update(fileInfo) {
			vm?.setFile(fileInfo)
		},
		destroy() {
			app?.unmount()
			app = null
			vm = null
		},
	}))
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', register)
} else {
	register()
}
