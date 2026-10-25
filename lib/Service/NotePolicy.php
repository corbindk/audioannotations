<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Service;

/**
 * Who may see or change a note, and what counts as valid input.
 * Plain values only, so it can be unit-tested without Nextcloud.
 */
class NotePolicy {
	public const COLORS = ['blue', 'amber', 'violet', 'green', 'red', 'grey'];
	public const VISIBILITIES = ['shared', 'private'];
	public const MAX_BODY_LENGTH = 10000;

	public function canView(string $visibility, string $authorUid, string $uid): bool {
		return $visibility === 'shared' || $authorUid === $uid;
	}

	public function canEdit(string $authorUid, string $uid): bool {
		return $authorUid === $uid;
	}

	/** Authors can delete and resolve their own notes; the file's owner can tidy up any note. */
	public function canDelete(string $authorUid, string $fileOwnerUid, string $uid): bool {
		return $authorUid === $uid || $fileOwnerUid === $uid;
	}

	public function canResolve(string $authorUid, string $fileOwnerUid, string $uid): bool {
		return $this->canDelete($authorUid, $fileOwnerUid, $uid);
	}

	/** A note with start and end both 0 is a general comment on the whole recording, not tied to a time. */
	public static function isGeneral(int $startMs, int $endMs): bool {
		return $startMs === 0 && $endMs === 0;
	}

	/**
	 * @param int $durationMs audio length, or 0 when not known yet
	 * @throws ValidationException
	 */
	public function validateRange(int $startMs, int $endMs, int $durationMs = 0): void {
		if ($startMs < 0) {
			throw new ValidationException('Start must not be negative');
		}
		if ($endMs < $startMs) {
			throw new ValidationException('End must not be before start');
		}
		if ($durationMs > 0 && $endMs > $durationMs) {
			throw new ValidationException('End is past the end of the recording');
		}
	}

	/** @throws ValidationException */
	public function normalizeBody(string $body): string {
		$body = trim($body);
		if ($body === '') {
			throw new ValidationException('Note text must not be empty');
		}
		if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
			throw new ValidationException('Note text is longer than ' . self::MAX_BODY_LENGTH . ' characters');
		}
		return $body;
	}

	/**
	 * Optional caption on a voice note: empty means none.
	 *
	 * @throws ValidationException
	 */
	public function normalizeCaption(?string $caption): ?string {
		$caption = trim($caption ?? '');
		if ($caption === '') {
			return null;
		}
		return $this->normalizeBody($caption);
	}

	/** @throws ValidationException */
	public function validateColor(string $color): void {
		if (!in_array($color, self::COLORS, true)) {
			throw new ValidationException('Unknown colour');
		}
	}

	/** @throws ValidationException */
	public function validateVisibility(string $visibility): void {
		if (!in_array($visibility, self::VISIBILITIES, true)) {
			throw new ValidationException('Visibility must be shared or private');
		}
	}
}
