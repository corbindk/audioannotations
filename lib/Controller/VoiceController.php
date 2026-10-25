<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Controller;

use OCA\AudioAnnotations\Http\RangeFileResponse;
use OCA\AudioAnnotations\Service\NoteService;
use OCA\AudioAnnotations\Service\NotFoundException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Plays a voice note's recording to anyone who can see the note, even though the file
 * lives in the author's storage. A plain route (not OCS) so <audio src> can use it directly.
 */
class VoiceController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private NoteService $notes,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	// Read-only GET used by <audio>, which cannot send the CSRF token; access is checked per note.
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/voice/{id}', requirements: ['id' => '\d+'])]
	public function stream(int $id): Response {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		try {
			$file = $this->notes->getVoiceFile($id, $uid);
		} catch (NotFoundException) {
			return new Response(Http::STATUS_NOT_FOUND);
		}
		if (trim($this->request->getHeader('If-None-Match'), ' "') === $file->getEtag()) {
			$response = new Response(Http::STATUS_NOT_MODIFIED);
			$response->setETag($file->getEtag());
			$response->addHeader('Cache-Control', 'private, no-cache');
			return $response;
		}
		$range = $this->request->getHeader('Range');
		return new RangeFileResponse($file, $range !== '' ? $range : null);
	}
}
