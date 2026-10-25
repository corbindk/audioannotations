<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Controller;

use OCA\AudioAnnotations\Service\ForbiddenException;
use OCA\AudioAnnotations\Service\NoteService;
use OCA\AudioAnnotations\Service\NotFoundException;
use OCA\AudioAnnotations\Service\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Notes on one audio file. Served under /ocs/v2.php/apps/audioannotations/api/v1.
 */
class NoteController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private NoteService $service,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/files/{fileId}/notes', requirements: ['fileId' => '\d+'])]
	public function index(int $fileId): DataResponse {
		return $this->handle(fn () => new DataResponse($this->service->list($fileId, $this->uid())));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/files/{fileId}/notes', requirements: ['fileId' => '\d+'])]
	public function create(int $fileId, int $startMs, int $endMs, string $body, string $color = 'blue', string $visibility = 'shared'): DataResponse {
		return $this->handle(fn () => new DataResponse(
			$this->service->create($fileId, $this->uid(), $startMs, $endMs, $body, $color, $visibility),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * Multipart upload: "audio" file plus range, optional caption and the recorder's duration.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/files/{fileId}/voice-notes', requirements: ['fileId' => '\d+'])]
	public function createVoice(int $fileId, int $startMs, int $endMs, int $durationMs = 0, string $caption = '', string $color = 'violet', string $visibility = 'shared'): DataResponse {
		return $this->handle(fn () => new DataResponse(
			$this->service->createVoice($fileId, $this->uid(), $startMs, $endMs, $caption, $this->request->getUploadedFile('audio'), $durationMs, $color, $visibility),
			Http::STATUS_CREATED,
		));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/notes/{id}', requirements: ['id' => '\d+'])]
	public function update(int $id, ?int $startMs = null, ?int $endMs = null, ?string $body = null, ?string $color = null, ?string $visibility = null, ?bool $resolved = null): DataResponse {
		$changes = array_filter(
			compact('startMs', 'endMs', 'body', 'color', 'visibility', 'resolved'),
			fn ($v) => $v !== null,
		);
		return $this->handle(fn () => new DataResponse($this->service->update($id, $this->uid(), $changes)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/notes/{id}', requirements: ['id' => '\d+'])]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->service->delete($id, $this->uid());
			return new DataResponse([]);
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/notes/{id}/replies', requirements: ['id' => '\d+'])]
	public function reply(int $id, string $body): DataResponse {
		return $this->handle(fn () => new DataResponse($this->service->addReply($id, $this->uid(), $body), Http::STATUS_CREATED));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/replies/{id}', requirements: ['id' => '\d+'])]
	public function destroyReply(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->service->deleteReply($id, $this->uid());
			return new DataResponse([]);
		});
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	/** @param callable(): DataResponse $action */
	private function handle(callable $action): DataResponse {
		try {
			return $action();
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
