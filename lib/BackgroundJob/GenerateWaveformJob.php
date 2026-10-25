<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\BackgroundJob;

use OCA\AudioAnnotations\Service\WaveformService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Decodes one audio file into waveform peaks. Never runs two at once, to keep the Pi responsive.
 */
class GenerateWaveformJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private WaveformService $waveforms,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	/** @param array{fileId: int, uid: string} $argument */
	#[\Override]
	protected function run($argument): void {
		$file = $this->rootFolder->getUserFolder($argument['uid'])->getFirstNodeById((int)$argument['fileId']);
		if (!($file instanceof File)) {
			$this->logger->info('Waveform job skipped: file ' . $argument['fileId'] . ' is gone', ['app' => 'audioannotations']);
			return;
		}
		$started = microtime(true);
		$result = $this->waveforms->generateAndStore($file);
		$this->logger->info(sprintf('Waveform for file %d: %s in %.1f s', $file->getId(), $result['status'] ?? '?', microtime(true) - $started), ['app' => 'audioannotations']);
	}
}
