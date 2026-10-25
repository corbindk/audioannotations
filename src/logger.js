/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getLoggerBuilder } from '@nextcloud/logger'

// Messages go to the browser console tagged with the app, at Nextcloud's configured log level.
export default getLoggerBuilder()
	.setApp('audioannotations')
	.detectUser()
	.build()
