/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Nextcloud's ESLint rules for a Vue 3 app written in plain JavaScript. `make lint` runs it.
import { recommendedJavascript } from '@nextcloud/eslint-config'

export default [
	...recommendedJavascript,
	{
		ignores: ['js/**', 'css/**', 'vendor/**', 'build/**'],
	},
	{
		// As in Nextcloud server: doc comments where they help, not on every function.
		// The other JSDoc rules still check the comments that exist.
		rules: {
			'jsdoc/require-jsdoc': 'off',
		},
	},
]
