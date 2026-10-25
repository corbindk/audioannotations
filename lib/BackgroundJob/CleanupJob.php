<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\BackgroundJob;

use OCA\AudioAnnotations\Db\NoteMapper;
use OCA\AudioAnnotations\Db\ReplyMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily: removes notes (and their replies) whose audio file is gone for good. Notes survive while the file sits
 * in a trash bin, because a restored file keeps its id. Voice recordings stay in their authors' files.
 */
class CleanupJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private NoteMapper $notes,
		private ReplyMapper $replies,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		$removed = 0;
		while (($ids = $this->notes->findOrphanIds()) !== []) {
			$this->replies->deleteByNotes($ids);
			$this->notes->deleteByIds($ids);
			$removed += count($ids);
		}
		if ($removed > 0) {
			$this->logger->info('Removed ' . $removed . ' notes on deleted audio files', ['app' => 'audioannotations']);
		}
	}
}
