<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Tests\Unit;

use OCA\AudioAnnotations\Service\PeakBuilder;
use PHPUnit\Framework\TestCase;

class PeakBuilderTest extends TestCase {
	public function testBucketsMinAndMaxScaled(): void {
		$b = new PeakBuilder(4);
		$b->addSamples([0, 32767, -32768, 100, 5, 6, 7, 8]);
		$this->assertSame([-127, 127, 0, 0], $b->finish());
		$this->assertSame(8, $b->sampleCount());
	}

	public function testChunkBoundariesDoNotMatter(): void {
		$samples = [];
		for ($i = 0; $i < 1003; $i++) {
			$samples[] = (int)(sin($i / 7) * 30000);
		}
		$whole = new PeakBuilder(10);
		$whole->addSamples($samples);

		$chunked = new PeakBuilder(10);
		foreach (array_chunk($samples, 37) as $chunk) {
			$chunked->addSamples($chunk);
		}
		$this->assertSame($whole->finish(), $chunked->finish());
	}

	public function testRawBytesWithOddSplits(): void {
		$bytes = pack('s*', 1000, -1000, 2000, -2000, 3000, -3000);
		$b = new PeakBuilder(2);
		foreach (str_split($bytes, 3) as $piece) {
			$b->addBytes($piece);
		}
		$this->assertSame(6, $b->sampleCount());
		$this->assertCount(6, $b->finish(), '3 buckets, 2 values each');
	}

	public function testPartialLastBucketIsKept(): void {
		$b = new PeakBuilder(4);
		$b->addSamples([1, 2, 3, 4, 32767]);
		$this->assertCount(4, $b->finish());
	}

	public function testDownsampleMergesExtremes(): void {
		$peaks = [-10, 10, -50, 5, -1, 90, -3, 3];
		$this->assertSame([-50, 10, -3, 90], PeakBuilder::downsample($peaks, 2));
		$this->assertSame($peaks, PeakBuilder::downsample($peaks, 10), 'never upsamples');
	}
}
