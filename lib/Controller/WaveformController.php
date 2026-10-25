<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Controller;

use OCA\AudioAnnotations\Service\AccessService;
use OCA\AudioAnnotations\Service\NotFoundException;
use OCA\AudioAnnotations\Service\WaveformService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class WaveformController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessService $access,
		private WaveformService $waveforms,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * 200 with peaks (or status skipped/failed) when cached; 202 after queueing generation.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/files/{fileId}/waveform', requirements: ['fileId' => '\d+'])]
	public function show(int $fileId): DataResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		try {
			$file = $this->access->getAudioFile($fileId, $uid);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
		$cached = $this->waveforms->getCached($file);
		if ($cached !== null) {
			return new DataResponse($cached);
		}
		$this->waveforms->queue($file, $uid);
		return new DataResponse(['status' => 'pending'], Http::STATUS_ACCEPTED);
	}
}
