<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Nextcloud's PHP coding standard (nextcloud/coding-standard). `make lint` checks, `make cs-fix` rewrites.
require_once './vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->ignoreVCSIgnored(true)
	->notPath('build')
	->notPath('js')
	->notPath('node_modules')
	->notPath('vendor')
	->in(__DIR__);

return $config;
