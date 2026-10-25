<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method int getStartMs()
 * @method void setStartMs(int $startMs)
 * @method int getEndMs()
 * @method void setEndMs(int $endMs)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string|null getBody()
 * @method void setBody(?string $body)
 * @method int|null getVoiceFileId()
 * @method void setVoiceFileId(?int $voiceFileId)
 * @method int|null getVoiceDurationMs()
 * @method void setVoiceDurationMs(?int $voiceDurationMs)
 * @method string getColor()
 * @method void setColor(string $color)
 * @method string getVisibility()
 * @method void setVisibility(string $visibility)
 * @method bool getResolved()
 * @method void setResolved(bool $resolved)
 * @method string|null getAudioEtag()
 * @method void setAudioEtag(?string $audioEtag)
 * @method int getAudioDurationMs()
 * @method void setAudioDurationMs(int $audioDurationMs)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Note extends Entity {
	protected $fileId;
	protected $ownerUid;
	protected $startMs;
	protected $endMs;
	protected $kind = 'text';
	protected $body;
	protected $voiceFileId;
	protected $voiceDurationMs;
	protected $color = 'blue';
	protected $visibility = 'shared';
	protected $resolved = false;
	protected $audioEtag;
	protected $audioDurationMs = 0;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('startMs', 'integer');
		$this->addType('endMs', 'integer');
		$this->addType('voiceFileId', 'integer');
		$this->addType('voiceDurationMs', 'integer');
		$this->addType('resolved', 'boolean');
		$this->addType('audioDurationMs', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}
}
