<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Service;

/**
 * A single HTTP byte range. Plain PHP so it can be unit-tested.
 * Multi-range requests are answered with the whole file, which browsers accept.
 */
final class ByteRange {
	private function __construct(
		public readonly int $start,
		public readonly int $end,
	) {
	}

	public function length(): int {
		return $this->end - $this->start + 1;
	}

	/**
	 * @return self|null|false the range; null when the header asks for the whole file
	 *                         (absent, multi-range or unparsable); false when it can't be satisfied (416)
	 */
	public static function parse(?string $header, int $size): self|null|false {
		if ($header === null || !preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) || ($m[1] === '' && $m[2] === '')) {
			return null;
		}
		if ($size === 0) {
			return false;
		}
		if ($m[1] === '') {
			// suffix range: the last N bytes
			$suffix = (int)$m[2];
			if ($suffix === 0) {
				return false;
			}
			return new self(max(0, $size - $suffix), $size - 1);
		}
		$start = (int)$m[1];
		$end = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
		if ($start >= $size || $end < $start) {
			return false;
		}
		return new self($start, $end);
	}
}
