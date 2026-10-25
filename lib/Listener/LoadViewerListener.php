<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Listener;

use OCA\AudioAnnotations\AppInfo\Application;
use OCA\Viewer\Event\LoadViewer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/** @template-implements IEventListener<LoadViewer> */
class LoadViewerListener implements IEventListener {
	public function __construct(
		private IUserSession $userSession,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		// The Viewer also loads on public share pages; notes need an account, so those keep the stock player.
		if (!($event instanceof LoadViewer) || !$this->userSession->isLoggedIn()) {
			return;
		}
		// After the viewer's own scripts, so OCA.Viewer exists when ours runs.
		Util::addScript(Application::APP_ID, 'audioannotations-viewer', 'viewer');
		Util::addStyle(Application::APP_ID, 'audioannotations');
	}
}
