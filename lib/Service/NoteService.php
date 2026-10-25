<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Service;

use OCA\AudioAnnotations\Db\Note;
use OCA\AudioAnnotations\Db\NoteMapper;
use OCA\AudioAnnotations\Db\Reply;
use OCA\AudioAnnotations\Db\ReplyMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IURLGenerator;
use OCP\IUserManager;

class NoteService {
	public function __construct(
		private NoteMapper $mapper,
		private AccessService $access,
		private NotePolicy $policy,
		private IUserManager $userManager,
		private ITimeFactory $time,
		private WaveformService $waveforms,
		private VoiceStorage $voices,
		private IURLGenerator $urlGenerator,
		private ReplyMapper $replies,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 * @throws NotFoundException
	 */
	public function list(int $fileId, string $uid): array {
		$file = $this->access->getAudioFile($fileId, $uid);
		$fileOwner = $this->access->getOwnerUid($file);
		$knownMs = $this->waveforms->knownDurationMs($file);
		$visible = array_values(array_filter(
			$this->mapper->findByFile($fileId),
			fn (Note $n) => $this->policy->canView($n->getVisibility(), $n->getOwnerUid(), $uid),
		));
		$replies = $this->replies->findByNotes(array_map(fn (Note $n) => $n->getId(), $visible));
		return array_map(function (Note $n) use ($uid, $fileOwner, $knownMs, $replies) {
			// Notes made before the waveform existed learn the recording's length the first time it is known.
			if ($n->getAudioDurationMs() === 0 && $knownMs > 0) {
				$n->setAudioDurationMs($knownMs);
				$n = $this->mapper->update($n);
			}
			return $this->serialize($n, $uid, $fileOwner, $replies[$n->getId()] ?? [], $knownMs);
		}, $visible);
	}

	/**
	 * @return array<string, mixed> the reply
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function addReply(int $noteId, string $uid, string $body): array {
		[$note, $fileOwner] = $this->loadVisible($noteId, $uid);
		$reply = new Reply();
		$reply->setNoteId($note->getId());
		$reply->setOwnerUid($uid);
		$reply->setBody($this->policy->normalizeBody($body));
		$reply->setCreatedAt($this->time->getTime());
		return $this->serializeReply($this->replies->insert($reply), $uid, $fileOwner);
	}

	/**
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	public function deleteReply(int $replyId, string $uid): void {
		try {
			$reply = $this->replies->find($replyId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Reply not found');
		}
		[, $fileOwner] = $this->loadVisible($reply->getNoteId(), $uid);
		if (!$this->policy->canDelete($reply->getOwnerUid(), $fileOwner, $uid)) {
			throw new ForbiddenException('Only the author or the file owner can delete this reply');
		}
		$this->replies->delete($reply);
	}

	/**
	 * Visible notes as Markdown or as an Audacity label track (tab-separated seconds).
	 *
	 * @return array{0: string, 1: string, 2: string} content, file name, mime type
	 * @throws NotFoundException
	 */
	public function export(int $fileId, string $uid, string $format): array {
		$file = $this->access->getAudioFile($fileId, $uid);
		$notes = $this->list($fileId, $uid);
		// General comments first, then the timed notes in time order (usort is stable).
		usort($notes, fn (array $a, array $b) => (int)$b['general'] <=> (int)$a['general']);
		$base = pathinfo($file->getName(), PATHINFO_FILENAME);
		if ($format === 'audacity') {
			$lines = array_map(function (array $n) {
				$label = $n['body'] ?? '';
				if ($n['kind'] === 'voice') {
					$label = trim('[voice] ' . $label);
				}
				$label = ($n['general'] ? '[general] ' : '') . $n['owner']['displayName'] . ': ' . preg_replace('/\s+/', ' ', $label);
				return sprintf("%.6f\t%.6f\t%s", $n['startMs'] / 1000, $n['endMs'] / 1000, $label);
			}, $notes);
			return [implode("\n", $lines) . "\n", $base . ' - labels.txt', 'text/plain'];
		}
		$out = '# Notes on ' . $file->getName() . "\n\n";
		foreach ($notes as $n) {
			$range = $n['general'] ? 'General' : self::clock($n['startMs']) . '–' . self::clock($n['endMs']);
			$text = $n['kind'] === 'voice'
				? '_Voice note, ' . self::clock((int)$n['voiceDurationMs']) . '_' . ($n['body'] ? ': ' . $n['body'] : '')
				: (string)$n['body'];
			$flags = ($n['resolved'] ? ' (resolved)' : '') . ($n['visibility'] === 'private' ? ' (private)' : '');
			$out .= '- **' . $range . '** · ' . $n['owner']['displayName'] . $flags . ': ' . str_replace("\n", "\n  ", $text) . "\n";
			foreach ($n['replies'] as $r) {
				$out .= '  - ' . $r['owner']['displayName'] . ': ' . str_replace("\n", "\n    ", $r['body']) . "\n";
			}
		}
		return [$out, $base . ' - notes.md', 'text/markdown'];
	}

	private static function clock(int $ms): string {
		$s = intdiv($ms, 1000);
		return $s >= 3600
			? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60)
			: sprintf('%d:%02d', intdiv($s, 60), $s % 60);
	}

	/**
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function create(int $fileId, string $uid, int $startMs, int $endMs, string $body, string $color, string $visibility): array {
		$file = $this->access->getAudioFile($fileId, $uid);
		$durationMs = $this->waveforms->knownDurationMs($file);
		$this->policy->validateRange($startMs, $endMs, $durationMs);
		$this->policy->validateColor($color);
		$this->policy->validateVisibility($visibility);

		$now = $this->time->getTime();
		$note = new Note();
		$note->setFileId($fileId);
		$note->setOwnerUid($uid);
		$note->setStartMs($startMs);
		$note->setEndMs($endMs);
		$note->setKind('text');
		$note->setBody($this->policy->normalizeBody($body));
		$note->setColor($color);
		$note->setVisibility($visibility);
		$note->setResolved(false);
		$note->setAudioEtag($file->getEtag());
		$note->setAudioDurationMs($durationMs);
		$note->setCreatedAt($now);
		$note->setUpdatedAt($now);
		$note = $this->mapper->insert($note);

		return $this->serialize($note, $uid, $this->access->getOwnerUid($file));
	}

	/**
	 * Store an uploaded recording in the author's files and pin it to a range.
	 *
	 * @param array{tmp_name?: string, size?: int, error?: int}|null $upload
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function createVoice(int $fileId, string $uid, int $startMs, int $endMs, ?string $caption, ?array $upload, int $clientDurationMs, string $color, string $visibility): array {
		$file = $this->access->getAudioFile($fileId, $uid);
		$durationMs = $this->waveforms->knownDurationMs($file);
		$this->policy->validateRange($startMs, $endMs, $durationMs);
		$this->policy->validateColor($color);
		$this->policy->validateVisibility($visibility);
		$caption = $this->policy->normalizeCaption($caption);
		['ext' => $ext, 'durationMs' => $voiceMs, 'path' => $voicePath] = $this->voices->validate($upload, $clientDurationMs);
		$voiceFile = $this->voices->save($uid, $file, $voicePath, $ext);

		$now = $this->time->getTime();
		$note = new Note();
		$note->setFileId($fileId);
		$note->setOwnerUid($uid);
		$note->setStartMs($startMs);
		$note->setEndMs($endMs);
		$note->setKind('voice');
		$note->setBody($caption);
		$note->setVoiceFileId($voiceFile->getId());
		$note->setVoiceDurationMs($voiceMs);
		$note->setColor($color);
		$note->setVisibility($visibility);
		$note->setResolved(false);
		$note->setAudioEtag($file->getEtag());
		$note->setAudioDurationMs($durationMs);
		$note->setCreatedAt($now);
		$note->setUpdatedAt($now);
		try {
			$note = $this->mapper->insert($note);
		} catch (\Throwable $e) {
			$this->voices->delete($uid, $voiceFile->getId());
			throw $e;
		}
		return $this->serialize($note, $uid, $this->access->getOwnerUid($file));
	}

	/**
	 * The recording behind a voice note, if this user may see the note.
	 *
	 * @throws NotFoundException
	 */
	public function getVoiceFile(int $noteId, string $uid): File {
		[$note] = $this->loadVisible($noteId, $uid);
		$voice = $note->getKind() === 'voice' && $note->getVoiceFileId() !== null
			? $this->voices->find($note->getOwnerUid(), $note->getVoiceFileId())
			: null;
		if ($voice === null) {
			throw new NotFoundException('This voice recording is no longer available.');
		}
		return $voice;
	}

	/**
	 * @param array{startMs?: int, endMs?: int, body?: string, color?: string, visibility?: string, resolved?: bool} $changes
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 * @throws ValidationException
	 */
	public function update(int $noteId, string $uid, array $changes): array {
		[$note, $fileOwner, $file] = $this->loadVisible($noteId, $uid);
		$author = $note->getOwnerUid();

		$editsContent = array_diff(array_keys($changes), ['resolved']) !== [];
		if ($editsContent && !$this->policy->canEdit($author, $uid)) {
			throw new ForbiddenException('Only the author can edit this note');
		}
		if (array_key_exists('resolved', $changes) && !$this->policy->canResolve($author, $fileOwner, $uid)) {
			throw new ForbiddenException('Only the author or the file owner can resolve this note');
		}

		$start = $changes['startMs'] ?? $note->getStartMs();
		$end = $changes['endMs'] ?? $note->getEndMs();
		$this->policy->validateRange($start, $end, $this->waveforms->knownDurationMs($file));
		$note->setStartMs($start);
		$note->setEndMs($end);
		if (isset($changes['body'])) {
			$note->setBody($note->getKind() === 'voice'
				? $this->policy->normalizeCaption($changes['body'])
				: $this->policy->normalizeBody($changes['body']));
		}
		if (isset($changes['color'])) {
			$this->policy->validateColor($changes['color']);
			$note->setColor($changes['color']);
		}
		if (isset($changes['visibility'])) {
			$this->policy->validateVisibility($changes['visibility']);
			$note->setVisibility($changes['visibility']);
		}
		if (isset($changes['resolved'])) {
			$note->setResolved($changes['resolved']);
		}
		$note->setUpdatedAt($this->time->getTime());
		$note = $this->mapper->update($note);

		// The response replaces the note in the client, so it carries the replies and stale flag like list() does.
		$replies = $this->replies->findByNotes([$note->getId()])[$note->getId()] ?? [];
		return $this->serialize($note, $uid, $fileOwner, $replies, $this->waveforms->knownDurationMs($file));
	}

	/**
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	public function delete(int $noteId, string $uid): void {
		[$note, $fileOwner] = $this->loadVisible($noteId, $uid);
		if (!$this->policy->canDelete($note->getOwnerUid(), $fileOwner, $uid)) {
			throw new ForbiddenException('Only the author or the file owner can delete this note');
		}
		$this->replies->deleteByNotes([$note->getId()]);
		$this->mapper->delete($note);
		// Only the author's own request can move the recording to their trash bin; from anyone
		// else's session Nextcloud would delete it permanently, so it stays in their files instead.
		if ($note->getKind() === 'voice' && $note->getVoiceFileId() !== null && $note->getOwnerUid() === $uid) {
			$this->voices->delete($uid, $note->getVoiceFileId());
		}
	}

	/**
	 * @return array{0: Note, 1: string, 2: File} the note, the audio file's owner, the audio file
	 * @throws NotFoundException
	 */
	private function loadVisible(int $noteId, string $uid): array {
		try {
			$note = $this->mapper->find($noteId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Note not found');
		}
		$file = $this->access->getAudioFile($note->getFileId(), $uid);
		if (!$this->policy->canView($note->getVisibility(), $note->getOwnerUid(), $uid)) {
			throw new NotFoundException('Note not found');
		}
		return [$note, $this->access->getOwnerUid($file), $file];
	}

	/**
	 * @param list<Reply> $replies
	 * @param int $knownMs the recording's current length, 0 when not known yet
	 * @return array<string, mixed>
	 */
	private function serialize(Note $note, string $uid, string $fileOwner, array $replies = [], int $knownMs = 0): array {
		$author = $note->getOwnerUid();
		$madeAgainstMs = $note->getAudioDurationMs();
		return [
			'id' => $note->getId(),
			'fileId' => $note->getFileId(),
			'owner' => [
				'uid' => $author,
				'displayName' => $this->userManager->getDisplayName($author) ?? $author,
			],
			'startMs' => $note->getStartMs(),
			'endMs' => $note->getEndMs(),
			'general' => NotePolicy::isGeneral($note->getStartMs(), $note->getEndMs()),
			'kind' => $note->getKind(),
			'body' => $note->getBody(),
			'voiceUrl' => $note->getKind() === 'voice'
				? $this->urlGenerator->linkToRoute('audioannotations.voice.stream', ['id' => $note->getId()])
				: null,
			'voiceDurationMs' => $note->getVoiceDurationMs(),
			'color' => $note->getColor(),
			'visibility' => $note->getVisibility(),
			'resolved' => $note->getResolved(),
			// The recording's length changed by more than half a second since the note was made.
			'stale' => !NotePolicy::isGeneral($note->getStartMs(), $note->getEndMs()) && $madeAgainstMs > 0 && $knownMs > 0 && abs($knownMs - $madeAgainstMs) > 500,
			'canResolve' => $this->policy->canResolve($author, $fileOwner, $uid),
			'replies' => array_map(fn (Reply $r) => $this->serializeReply($r, $uid, $fileOwner), $replies),
			'canEdit' => $this->policy->canEdit($author, $uid),
			'canDelete' => $this->policy->canDelete($author, $fileOwner, $uid),
			'createdAt' => $note->getCreatedAt(),
			'updatedAt' => $note->getUpdatedAt(),
		];
	}

	/** @return array<string, mixed> */
	private function serializeReply(Reply $reply, string $uid, string $fileOwner): array {
		$author = $reply->getOwnerUid();
		return [
			'id' => $reply->getId(),
			'noteId' => $reply->getNoteId(),
			'owner' => [
				'uid' => $author,
				'displayName' => $this->userManager->getDisplayName($author) ?? $author,
			],
			'body' => $reply->getBody(),
			'canDelete' => $this->policy->canDelete($author, $fileOwner, $uid),
			'createdAt' => $reply->getCreatedAt(),
		];
	}
}
