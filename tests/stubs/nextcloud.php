<?php

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Psalm stubs for Nextcloud classes the app touches that are not in the nextcloud/ocp package.
// Only for static analysis; never loaded at runtime.

namespace OCA\Files\Event {
	class LoadSidebar extends \OCP\EventDispatcher\Event {
	}
}

namespace OCA\Viewer\Event {
	class LoadViewer extends \OCP\EventDispatcher\Event {
	}
}
