<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getNoteId()
 * @method void setNoteId(int $noteId)
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method string getBody()
 * @method void setBody(string $body)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Reply extends Entity {
	protected $noteId;
	protected $ownerUid;
	protected $body;
	protected $createdAt;

	public function __construct() {
		$this->addType('noteId', 'integer');
		$this->addType('createdAt', 'integer');
	}
}
