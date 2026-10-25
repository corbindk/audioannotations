<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Tests\Unit;

use OCA\AudioAnnotations\Service\ByteRange;
use OCA\AudioAnnotations\Service\NotePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ByteRangeTest extends TestCase {
	public static function ranges(): array {
		return [
			'from start' => ['bytes=0-99', 1000, [0, 99]],
			'open end' => ['bytes=500-', 1000, [500, 999]],
			'end past size is clamped' => ['bytes=900-5000', 1000, [900, 999]],
			'suffix' => ['bytes=-100', 1000, [900, 999]],
			'suffix bigger than file' => ['bytes=-5000', 1000, [0, 999]],
			'safari probe' => ['bytes=0-1', 1000, [0, 1]],
		];
	}

	#[DataProvider('ranges')]
	public function testParses(string $header, int $size, array $expected): void {
		$range = ByteRange::parse($header, $size);
		$this->assertNotNull($range);
		$this->assertNotFalse($range);
		$this->assertSame($expected, [$range->start, $range->end]);
		$this->assertSame($expected[1] - $expected[0] + 1, $range->length());
	}

	public function testWholeFileWhenAbsentOrUnsupported(): void {
		$this->assertNull(ByteRange::parse(null, 1000));
		$this->assertNull(ByteRange::parse('bytes=0-1,5-9', 1000), 'multi-range');
		$this->assertNull(ByteRange::parse('items=0-5', 1000));
		$this->assertNull(ByteRange::parse('bytes=-', 1000));
	}

	public function testUnsatisfiable(): void {
		$this->assertFalse(ByteRange::parse('bytes=1000-', 1000));
		$this->assertFalse(ByteRange::parse('bytes=50-10', 1000));
		$this->assertFalse(ByteRange::parse('bytes=-0', 1000));
		$this->assertFalse(ByteRange::parse('bytes=0-10', 0));
	}

	public function testCaptionIsOptional(): void {
		$policy = new NotePolicy();
		$this->assertNull($policy->normalizeCaption(null));
		$this->assertNull($policy->normalizeCaption("  \n"));
		$this->assertSame('Bridge', $policy->normalizeCaption(' Bridge '));
	}
}
