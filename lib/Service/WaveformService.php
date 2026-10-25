<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Service;

use OCA\AudioAnnotations\BackgroundJob\GenerateWaveformJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IBinaryFinder;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Waveform peaks per file version, generated once by a background job and cached as app data
 * under waveforms/<fileid>-<etag>-v<FORMAT>.json. A new etag means a new cache file.
 *
 * About 10 peak pairs per second (2000 minimum, 50000 maximum) so the player can zoom to
 * roughly 100 ms detail on recordings up to ~80 minutes, and ~7 per second on a 2-hour one.
 */
class WaveformService {
	public const FORMAT = 2;
	public const PEAKS_PER_SECOND = 10;
	public const MIN_BUCKETS = 2000;
	public const MAX_BUCKETS = 50000;
	public const OVERVIEW_BUCKETS = 200;
	public const SAMPLE_RATE = 8000;
	public const MAX_BYTES = 2 * 1024 * 1024 * 1024;
	public const MAX_DURATION_MS = 4 * 3600 * 1000;
	public const TIMEOUT_SECONDS = 600;

	public function __construct(
		private IAppData $appData,
		private IBinaryFinder $binaryFinder,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	/** @return array<string, mixed>|null the cached result, or null when not generated yet */
	public function getCached(File $file): ?array {
		try {
			$data = json_decode($this->folder()->getFile($this->cacheName($file))->getContent(), true);
			return is_array($data) ? $data : null;
		} catch (FilesNotFoundException) {
			return null;
		}
	}

	/** Duration in ms when a usable waveform exists, else 0. */
	public function knownDurationMs(File $file): int {
		$cached = $this->getCached($file);
		return (int)($cached['durationMs'] ?? 0);
	}

	/** Queue generation unless a job for this file is already waiting. */
	public function queue(File $file, string $uid): void {
		$args = ['fileId' => $file->getId(), 'uid' => $uid];
		if (!$this->jobList->has(GenerateWaveformJob::class, $args)) {
			$this->jobList->add(GenerateWaveformJob::class, $args);
		}
	}

	/** Generate (or record why we skipped) and cache it. */
	public function generateAndStore(File $file): array {
		$cached = $this->getCached($file);
		if ($cached !== null) {
			return $cached;
		}
		try {
			$result = $this->generate($file);
		} catch (RuntimeException $e) {
			$this->logger->warning('Waveform generation failed for file ' . $file->getId() . ': ' . $e->getMessage(), ['app' => 'audioannotations']);
			$result = ['version' => self::FORMAT, 'status' => 'failed', 'reason' => 'This recording could not be decoded.'];
		}
		$folder = $this->folder();
		$folder->newFile($this->cacheName($file), json_encode($result));
		$this->pruneOldVersions($folder, $file);
		return $result;
	}

	/** @return array<string, mixed> */
	private function generate(File $file): array {
		if ($file->getSize() > self::MAX_BYTES) {
			return ['version' => self::FORMAT, 'status' => 'skipped', 'reason' => 'Recordings over 2 GB have no waveform.'];
		}
		$path = $file->getStorage()->getLocalFile($file->getInternalPath());
		if ($path === false) {
			throw new RuntimeException('No local copy of the file');
		}
		$ffmpeg = $this->binaryFinder->findBinaryPath('ffmpeg');
		$ffprobe = $this->binaryFinder->findBinaryPath('ffprobe');
		if ($ffmpeg === false || $ffprobe === false) {
			throw new RuntimeException('ffmpeg/ffprobe not installed');
		}

		$probedMs = $this->probeDurationMs($ffprobe, $path);
		if ($probedMs > self::MAX_DURATION_MS) {
			return ['version' => self::FORMAT, 'status' => 'skipped', 'durationMs' => $probedMs, 'reason' => 'Recordings over 4 hours have no waveform.'];
		}

		$seconds = (float)$probedMs / 1000.0;
		$expectedSamples = (int)ceil($seconds * (float)self::SAMPLE_RATE);
		$buckets = min(self::MAX_BUCKETS, max(self::MIN_BUCKETS, (int)round($seconds * (float)self::PEAKS_PER_SECOND)));
		$builder = new PeakBuilder((int)ceil(max(1, $expectedSamples) / $buckets));
		$this->decode($ffmpeg, $path, $builder);
		$peaks = $builder->finish();
		if ($peaks === []) {
			throw new RuntimeException('No audio samples decoded');
		}

		return [
			'version' => self::FORMAT,
			'status' => 'ready',
			'durationMs' => (int)round((float)$builder->sampleCount() / (float)self::SAMPLE_RATE * 1000.0),
			'peaks' => $peaks,
			'overview' => PeakBuilder::downsample($peaks, self::OVERVIEW_BUCKETS),
		];
	}

	/** Length of a local media file in ms, or null when ffprobe is missing or the file has no duration. */
	public function probeLocalDurationMs(string $path): ?int {
		$ffprobe = $this->binaryFinder->findBinaryPath('ffprobe');
		if ($ffprobe === false) {
			return null;
		}
		try {
			return $this->probeDurationMs($ffprobe, $path);
		} catch (RuntimeException) {
			return null;
		}
	}

	/**
	 * Rewrite a WebM recording without re-encoding. Browsers' MediaRecorder streams WebM with no duration
	 * and no seek index; ffmpeg writes both when it can seek back in the output file.
	 */
	public function remuxWebm(string $in, string $out): bool {
		$ffmpeg = $this->binaryFinder->findBinaryPath('ffmpeg');
		if ($ffmpeg === false) {
			return false;
		}
		try {
			$this->run([$ffmpeg, '-v', 'error', '-nostdin', '-y', '-i', $in, '-map', '0:a', '-c', 'copy', '-f', 'webm', $out], 30);
		} catch (RuntimeException $e) {
			$this->logger->info('Could not remux voice note: ' . $e->getMessage(), ['app' => 'audioannotations']);
			return false;
		}
		return filesize($out) > 0;
	}

	private function probeDurationMs(string $ffprobe, string $path): int {
		$out = $this->run([$ffprobe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $path], 30);
		$seconds = (float)trim($out);
		if ($seconds <= 0) {
			throw new RuntimeException('ffprobe found no duration');
		}
		return (int)round($seconds * 1000.0);
	}

	/** Stream ffmpeg's mono 8 kHz s16le output into the builder. */
	private function decode(string $ffmpeg, string $path, PeakBuilder $builder): void {
		$cmd = ['nice', '-n', '19', $ffmpeg, '-v', 'error', '-nostdin', '-i', $path,
			'-ac', '1', '-ar', (string)self::SAMPLE_RATE, '-f', 's16le', '-'];
		$this->run($cmd, self::TIMEOUT_SECONDS, fn (string $chunk) => $builder->addBytes($chunk));
	}

	/**
	 * Run a command without a shell. Returns stdout unless $onChunk consumes it.
	 *
	 * @param list<string> $cmd
	 * @param (callable(string): void)|null $onChunk
	 */
	private function run(array $cmd, int $timeout, ?callable $onChunk = null): string {
		$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($proc)) {
			throw new RuntimeException('Could not start ' . $cmd[0]);
		}
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$deadline = time() + $timeout;
		$out = '';
		$err = '';
		while (!feof($pipes[1]) || !feof($pipes[2])) {
			if (time() > $deadline) {
				proc_terminate($proc, 9);
				proc_close($proc);
				throw new RuntimeException('Timed out after ' . $timeout . ' s');
			}
			$read = array_filter([$pipes[1], $pipes[2]], fn ($p) => !feof($p));
			$write = $except = null;
			if (stream_select($read, $write, $except, 1) === false) {
				break;
			}
			foreach ($read as $pipe) {
				$data = fread($pipe, 1 << 16);
				if ($data === false || $data === '') {
					continue;
				}
				if ($pipe === $pipes[2]) {
					$err = substr($err . $data, -2000);
				} elseif ($onChunk !== null) {
					$onChunk($data);
				} else {
					$out .= $data;
				}
			}
		}
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		if ($code !== 0) {
			throw new RuntimeException(basename($cmd[0] === 'nice' ? $cmd[3] : $cmd[0]) . ' exited ' . $code . ': ' . trim($err));
		}
		return $out;
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder('waveforms');
		} catch (FilesNotFoundException) {
			return $this->appData->newFolder('waveforms');
		}
	}

	private function cacheName(File $file): string {
		return $file->getId() . '-' . preg_replace('/[^A-Za-z0-9]/', '', $file->getEtag()) . '-v' . self::FORMAT . '.json';
	}

	private function pruneOldVersions(ISimpleFolder $folder, File $file): void {
		$keep = $this->cacheName($file);
		foreach ($folder->getDirectoryListing() as $cached) {
			if (str_starts_with($cached->getName(), $file->getId() . '-') && $cached->getName() !== $keep) {
				$cached->delete();
			}
		}
	}
}
