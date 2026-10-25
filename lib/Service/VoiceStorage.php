<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Voice-note recordings live as ordinary files in the author's own storage, under
 * "Audio Annotations/<recording name>/<date time>.<ext>", so they count against the author's quota,
 * show up in Files and are covered by file backups. Other users reach them only through the note.
 */
class VoiceStorage {
	public const FOLDER = 'Audio Annotations';
	public const MAX_BYTES = 5 * 1024 * 1024;
	public const MAX_DURATION_MS = 5 * 60 * 1000;
	private const DURATION_TOLERANCE_MS = 5000;
	/** What browsers' MediaRecorder produces (finfo often reports audio-only WebM/MP4 as video/*). */
	private const TYPES = [
		'audio/webm' => 'webm',
		'video/webm' => 'webm',
		'audio/ogg' => 'ogg',
		'audio/mp4' => 'm4a',
		'video/mp4' => 'm4a',
		'audio/x-m4a' => 'm4a',
		'audio/aac' => 'aac',
		'audio/mpeg' => 'mp3',
	];

	public function __construct(
		private IRootFolder $rootFolder,
		private WaveformService $waveforms,
		private ITimeFactory $time,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Check an uploaded recording.
	 *
	 * @param array{tmp_name?: string, size?: int, error?: int}|null $upload one entry from $_FILES
	 * @param int $clientDurationMs the recorder's own timer, used when the file has no length in it
	 * @return array{ext: string, durationMs: int, path: string} path is the file to store (a repaired copy for WebM)
	 * @throws ValidationException
	 */
	public function validate(?array $upload, int $clientDurationMs): array {
		if ($upload === null || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) {
			throw new ValidationException('No recording was received. Try saving it again.');
		}
		$size = (int)($upload['size'] ?? 0);
		if ($size <= 0 || $size > self::MAX_BYTES) {
			throw new ValidationException('Voice notes can be at most 5 MB.');
		}
		$mime = (new \finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']) ?: '';
		$ext = self::TYPES[$mime] ?? null;
		if ($ext === null) {
			throw new ValidationException('This recording format is not supported.');
		}
		$path = $upload['tmp_name'];
		// Browser WebM has no duration or seek index, so players show no length and can't seek. Store a remuxed copy.
		if ($ext === 'webm') {
			$fixed = $this->tempManager->getTemporaryFile('.webm');
			if ($fixed !== false && $this->waveforms->remuxWebm($path, $fixed)) {
				$path = $fixed;
			}
		}
		// Fall back to the recorder's timer when the file still has no length in it.
		$durationMs = $this->waveforms->probeLocalDurationMs($path) ?? $clientDurationMs;
		if ($durationMs <= 0) {
			throw new ValidationException('Could not read the length of the recording.');
		}
		if ($durationMs > self::MAX_DURATION_MS + self::DURATION_TOLERANCE_MS) {
			throw new ValidationException('Voice notes can be at most 5 minutes long.');
		}
		return ['ext' => $ext, 'durationMs' => min($durationMs, self::MAX_DURATION_MS), 'path' => $path];
	}

	/** @throws ValidationException when the author's storage refuses the file (quota, permissions) */
	public function save(string $uid, File $audio, string $tmpPath, string $ext): File {
		try {
			$folder = $this->ensureFolder(
				$this->rootFolder->getUserFolder($uid),
				[self::FOLDER, $this->folderNameFor($audio)],
			);
			$base = $this->time->getDateTime()->format('Y-m-d His');
			$name = $base . '.' . $ext;
			for ($i = 2; $folder->nodeExists($name); $i++) {
				$name = $base . ' (' . $i . ').' . $ext;
			}
			$source = fopen($tmpPath, 'rb');
			$file = $folder->newFile($name, $source);
			if (is_resource($source)) {
				fclose($source);
			}
			return $file;
		} catch (Throwable $e) {
			$this->logger->warning('Could not store voice note for ' . $uid . ': ' . $e->getMessage(), ['app' => 'audioannotations', 'exception' => $e]);
			throw new ValidationException('Could not save the recording in your files. Your storage may be full.');
		}
	}

	public function find(string $authorUid, int $fileId): ?File {
		try {
			$node = $this->rootFolder->getUserFolder($authorUid)->getFirstNodeById($fileId);
		} catch (Throwable) {
			return null;
		}
		return $node instanceof File ? $node : null;
	}

	/** Moves the recording to the author's trash bin. */
	public function delete(string $authorUid, int $fileId): void {
		try {
			$this->find($authorUid, $fileId)?->delete();
		} catch (Throwable $e) {
			$this->logger->warning('Could not delete voice note file ' . $fileId . ': ' . $e->getMessage(), ['app' => 'audioannotations']);
		}
	}

	/** @param list<string> $names */
	private function ensureFolder(Folder $folder, array $names): Folder {
		foreach ($names as $name) {
			$folder = $this->childFolder($folder, $name);
		}
		return $folder;
	}

	/** The folder "<name>", or "<name> (notes)" when a file already uses that name. Reused on later saves. */
	private function childFolder(Folder $parent, string $name): Folder {
		foreach ([$name, $name . ' (notes)'] as $candidate) {
			try {
				$node = $parent->get($candidate);
			} catch (FilesNotFoundException) {
				return $parent->newFolder($candidate);
			}
			if ($node instanceof Folder) {
				return $node;
			}
		}
		throw new \RuntimeException('Both "' . $name . '" and "' . $name . ' (notes)" are taken by files');
	}

	private function folderNameFor(File $audio): string {
		$name = pathinfo($audio->getName(), PATHINFO_FILENAME);
		// Leading or trailing dots would make "." or ".." (or a hidden folder) from names like "..wav".
		$name = trim(preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $name) ?? '', " \t.");
		return mb_substr($name !== '' ? $name : 'Recording ' . $audio->getId(), 0, 100);
	}
}
