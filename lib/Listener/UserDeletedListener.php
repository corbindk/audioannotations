<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Listener;

use OCA\AudioAnnotations\Db\NoteMapper;
use OCA\AudioAnnotations\Db\ReplyMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * A deleted user's notes and replies go with them; their voice recordings go with their files.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private NoteMapper $notes,
		private ReplyMapper $replies,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}
		$uid = $event->getUser()->getUID();
		$noteIds = $this->notes->findIdsByOwner($uid);
		$this->replies->deleteByNotes($noteIds);
		$this->notes->deleteByIds($noteIds);
		$this->replies->deleteByOwner($uid);
	}
}
