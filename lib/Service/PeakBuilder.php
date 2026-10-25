<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Service;

/**
 * Turns a stream of signed 16-bit mono samples into min/max peak pairs.
 * Memory stays flat: only the current bucket's leftover samples are kept.
 * Plain PHP, so it can be unit-tested without Nextcloud or ffmpeg.
 */
class PeakBuilder {
	/** @var list<int> interleaved min,max per bucket, scaled to -127..127 */
	private array $peaks = [];
	/** @var list<int> samples carried over to the next chunk */
	private array $carry = [];
	private int $samples = 0;
	private string $oddByte = '';

	public function __construct(
		private int $bucketSize,
	) {
		$this->bucketSize = max(1, $bucketSize);
	}

	/** Feed raw s16le bytes, in any chunk size. */
	public function addBytes(string $bytes): void {
		$bytes = $this->oddByte . $bytes;
		$even = strlen($bytes) & ~1;
		$this->oddByte = substr($bytes, $even);
		if ($even === 0) {
			return;
		}
		$this->addSamples(array_values(unpack('s*', substr($bytes, 0, $even))));
	}

	/** @param list<int> $samples */
	public function addSamples(array $samples): void {
		$this->samples += count($samples);
		if ($this->carry !== []) {
			$samples = array_merge($this->carry, $samples);
			$this->carry = [];
		}
		$n = count($samples);
		$offset = 0;
		while ($offset + $this->bucketSize <= $n) {
			/** @var non-empty-list<int> $bucket bucketSize is at least 1 */
			$bucket = array_slice($samples, $offset, $this->bucketSize);
			$this->push($bucket);
			$offset += $this->bucketSize;
		}
		if ($offset < $n) {
			$this->carry = array_slice($samples, $offset);
		}
	}

	/** @return list<int> */
	public function finish(): array {
		if ($this->carry !== []) {
			$this->push($this->carry);
			$this->carry = [];
		}
		return $this->peaks;
	}

	public function sampleCount(): int {
		return $this->samples;
	}

	/**
	 * Merge peak pairs into fewer, wider buckets (for the minimap).
	 *
	 * @param list<int> $peaks interleaved min,max
	 * @return list<int>
	 */
	public static function downsample(array $peaks, int $buckets): array {
		$pairs = intdiv(count($peaks), 2);
		if ($pairs <= $buckets) {
			return $peaks;
		}
		$out = [];
		for ($b = 0; $b < $buckets; $b++) {
			$from = intdiv($b * $pairs, $buckets);
			$to = max($from + 1, intdiv(($b + 1) * $pairs, $buckets));
			$min = 127;
			$max = -127;
			for ($i = $from; $i < $to; $i++) {
				$min = min($min, $peaks[2 * $i]);
				$max = max($max, $peaks[2 * $i + 1]);
			}
			$out[] = $min;
			$out[] = $max;
		}
		return $out;
	}

	/** @param non-empty-list<int> $bucket */
	private function push(array $bucket): void {
		$this->peaks[] = self::scale(min($bucket));
		$this->peaks[] = self::scale(max($bucket));
	}

	private static function scale(int $sample): int {
		return (int)round((float)$sample / 32768.0 * 127.0);
	}
}
