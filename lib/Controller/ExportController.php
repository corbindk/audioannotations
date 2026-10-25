<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Controller;

use OCA\AudioAnnotations\Service\NoteService;
use OCA\AudioAnnotations\Service\NotFoundException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/** Downloads the notes a user can see on one recording. A plain GET so it works as a link. */
class ExportController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private NoteService $notes,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/export/{fileId}', requirements: ['fileId' => '\d+'])]
	public function download(int $fileId, string $format = 'md'): Response {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		try {
			[$content, $name, $mime] = $this->notes->export($fileId, $uid, $format === 'audacity' ? 'audacity' : 'md');
		} catch (NotFoundException) {
			return new Response(Http::STATUS_NOT_FOUND);
		}
		return new DataDownloadResponse($content, $name, $mime . '; charset=utf-8');
	}
}
