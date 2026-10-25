/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineConfig } from 'vitest/config'

// Unit tests for plain JavaScript helpers (no browser). `make test-js` runs them.
export default defineConfig({
	test: {
		include: ['tests/js/**/*.test.js'],
		environment: 'node',
	},
})
