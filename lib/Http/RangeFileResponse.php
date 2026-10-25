<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Http;

use OCA\AudioAnnotations\Service\ByteRange;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;

/**
 * Streams a file with HTTP Range support (206 Partial Content), which Safari needs to play audio.
 *
 * @template-extends Response<Http::STATUS_*, array<string, mixed>>
 */
class RangeFileResponse extends Response implements ICallbackResponse {
	private int $offset = 0;
	private int $length;

	public function __construct(
		private File $file,
		?string $rangeHeader,
	) {
		parent::__construct();
		$size = (int)$file->getSize();
		$this->length = $size;
		$range = ByteRange::parse($rangeHeader, $size);

		$this->addHeader('Content-Type', $file->getMimetype());
		$this->addHeader('Accept-Ranges', 'bytes');
		$this->addHeader('Content-Disposition', 'inline; filename="' . rawurlencode($file->getName()) . '"');
		// Revalidate every time: a recording can be replaced in place (e.g. repaired), and a stale cached copy
		// would keep the old bytes for as long as max-age allowed. Unchanged files cost a 304.
		$this->addHeader('Cache-Control', 'private, no-cache');
		$this->setETag($file->getEtag());

		if ($range === false) {
			$this->setStatus(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE);
			$this->addHeader('Content-Range', 'bytes */' . $size);
			$this->length = 0;
			return;
		}
		if ($range !== null) {
			$this->setStatus(Http::STATUS_PARTIAL_CONTENT);
			$this->addHeader('Content-Range', sprintf('bytes %d-%d/%d', $range->start, $range->end, $size));
			$this->offset = $range->start;
			$this->length = $range->length();
		}
		$this->addHeader('Content-Length', (string)$this->length);
	}

	#[\Override]
	public function callback(IOutput $output): void {
		if ($this->length === 0) {
			return;
		}
		$handle = $this->file->fopen('rb');
		if ($handle === false) {
			$output->setHttpResponseCode(Http::STATUS_NOT_FOUND);
			return;
		}
		if ($this->offset > 0) {
			fseek($handle, $this->offset);
		}
		$remaining = $this->length;
		while ($remaining > 0 && !feof($handle)) {
			$chunk = fread($handle, min(65536, $remaining));
			if ($chunk === false || $chunk === '') {
				break;
			}
			$output->setOutput($chunk);
			$remaining -= strlen($chunk);
		}
		fclose($handle);
	}
}
