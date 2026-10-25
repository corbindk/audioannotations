<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Service;

/** Also used when the user may not see something, so IDs can't be probed. */
class NotFoundException extends \Exception {
}
