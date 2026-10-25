<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Tests\Unit;

use OCA\AudioAnnotations\Service\NotePolicy;
use OCA\AudioAnnotations\Service\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NotePolicyTest extends TestCase {
	private NotePolicy $policy;

	protected function setUp(): void {
		$this->policy = new NotePolicy();
	}

	public static function viewCases(): array {
		return [
			'shared note, other user' => ['shared', 'alice', 'bob', true],
			'shared note, author' => ['shared', 'alice', 'alice', true],
			'private note, author' => ['private', 'alice', 'alice', true],
			'private note, other user' => ['private', 'alice', 'bob', false],
		];
	}

	#[DataProvider('viewCases')]
	public function testCanView(string $visibility, string $author, string $uid, bool $expected): void {
		$this->assertSame($expected, $this->policy->canView($visibility, $author, $uid));
	}

	public function testOnlyAuthorCanEdit(): void {
		$this->assertTrue($this->policy->canEdit('alice', 'alice'));
		$this->assertFalse($this->policy->canEdit('alice', 'bob'));
		$this->assertFalse($this->policy->canEdit('alice', 'owner'), 'file owner cannot edit others\' text');
	}

	public static function deleteCases(): array {
		return [
			'author' => ['alice', 'owner', 'alice', true],
			'file owner' => ['alice', 'owner', 'owner', true],
			'someone else' => ['alice', 'owner', 'bob', false],
			'author is file owner' => ['owner', 'owner', 'owner', true],
		];
	}

	#[DataProvider('deleteCases')]
	public function testCanDeleteAndResolve(string $author, string $fileOwner, string $uid, bool $expected): void {
		$this->assertSame($expected, $this->policy->canDelete($author, $fileOwner, $uid));
		$this->assertSame($expected, $this->policy->canResolve($author, $fileOwner, $uid));
	}

	public function testValidRanges(): void {
		$this->policy->validateRange(0, 0);
		$this->policy->validateRange(1000, 5000);
		$this->policy->validateRange(1000, 5000, 5000);
		$this->addToAssertionCount(3);
	}

	public static function badRanges(): array {
		return [
			'negative start' => [-1, 100, 0],
			'end before start' => [5000, 4000, 0],
			'end past duration' => [0, 6000, 5000],
		];
	}

	#[DataProvider('badRanges')]
	public function testInvalidRanges(int $start, int $end, int $duration): void {
		$this->expectException(ValidationException::class);
		$this->policy->validateRange($start, $end, $duration);
	}

	public function testGeneralNotes(): void {
		$this->assertTrue(NotePolicy::isGeneral(0, 0));
		$this->assertFalse(NotePolicy::isGeneral(0, 5000));
		$this->assertFalse(NotePolicy::isGeneral(4000, 4000));
	}

	public function testBodyIsTrimmed(): void {
		$this->assertSame('Intro drags', $this->policy->normalizeBody("  Intro drags \n"));
	}

	public function testEmptyBodyRejected(): void {
		$this->expectException(ValidationException::class);
		$this->policy->normalizeBody("   \n ");
	}

	public function testOverlongBodyRejected(): void {
		$this->expectException(ValidationException::class);
		$this->policy->normalizeBody(str_repeat('a', NotePolicy::MAX_BODY_LENGTH + 1));
	}

	public function testColorAndVisibilityLists(): void {
		$this->policy->validateColor('amber');
		$this->policy->validateVisibility('private');
		$this->addToAssertionCount(2);
		$this->expectException(ValidationException::class);
		$this->policy->validateColor('pink');
	}
}
