<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;

class AccessService {
	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * The audio file as this user sees it.
	 *
	 * @throws NotFoundException when it doesn't exist, isn't audio, or the user can't read it
	 */
	public function getAudioFile(int $fileId, string $uid): File {
		$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
		if (!($node instanceof File) || !$node->isReadable() || !str_starts_with($node->getMimetype(), 'audio/')) {
			throw new NotFoundException('Audio file not found');
		}
		return $node;
	}

	public function getOwnerUid(File $file): string {
		return $file->getOwner()?->getUID() ?? '';
	}
}
