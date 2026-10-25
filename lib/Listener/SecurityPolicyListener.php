<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\Listener;

use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\EmptyFeaturePolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\Security\FeaturePolicy\AddFeaturePolicyEvent;

/**
 * Nextcloud's default policies forbid the microphone ("microphone 'none'"), so Chromium browsers
 * (Chrome, Samsung Internet, Edge) refuse getUserMedia without ever asking the user. Firefox ignores
 * that header, which is why recording worked there. Voice notes need the microphone on our own
 * origin, and the recorder previews the take from a blob: URL before it is uploaded.
 *
 * @template-implements IEventListener<AddFeaturePolicyEvent|AddContentSecurityPolicyEvent>
 */
class SecurityPolicyListener implements IEventListener {
	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof AddFeaturePolicyEvent) {
			$policy = new EmptyFeaturePolicy();
			$policy->addAllowedMicrophoneDomain('\'self\'');
			$event->addPolicy($policy);
		} elseif ($event instanceof AddContentSecurityPolicyEvent) {
			$policy = new EmptyContentSecurityPolicy();
			$policy->addAllowedMediaDomain('blob:');
			$event->addPolicy($policy);
		}
	}
}
