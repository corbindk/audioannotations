/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { registerSidebarTab } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { createApp } from 'vue'
import NotesTab from './NotesTab.vue'

// mdi-note-text-outline
const iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M15,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V9L15,3M19,19H5V5H14V10H19M17,13H7V11H17V13M14,16H7V14H14"/></svg>'
const TAG = 'audioannotations-sidebar-tab'

// The Files sidebar renders tabs as web components and sets `node` (and `active`) on them.
// No shadow root, so the page's Nextcloud styles reach the Vue component inside.
class AnnotationsTab extends HTMLElement {
	#app = null
	#vm = null
	#node = null

	get node() {
		return this.#node
	}

	set node(node) {
		this.#node = node
		this.#vm?.setFile(node)
	}

	connectedCallback() {
		this.#app = createApp(NotesTab)
		this.#vm = this.#app.mount(this)
		if (this.#node) {
			this.#vm.setFile(this.#node)
		}
	}

	disconnectedCallback() {
		this.#app?.unmount()
		this.#app = null
		this.#vm = null
	}
}

registerSidebarTab({
	id: 'audioannotations',
	displayName: t('audioannotations', 'Annotations'),
	iconSvgInline: iconSvg,
	order: 50,
	tagName: TAG,
	enabled({ node }) {
		return node?.type === 'file' && (node?.mime ?? '').startsWith('audio/')
	},
	async onInit() {
		if (!customElements.get(TAG)) {
			customElements.define(TAG, AnnotationsTab)
		}
	},
})
