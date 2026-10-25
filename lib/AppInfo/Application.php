<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Corbin Keener
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AudioAnnotations\AppInfo;

use OCA\AudioAnnotations\Listener\LoadSidebarListener;
use OCA\AudioAnnotations\Listener\LoadViewerListener;
use OCA\AudioAnnotations\Listener\SecurityPolicyListener;
use OCA\AudioAnnotations\Listener\UserDeletedListener;
use OCA\Files\Event\LoadSidebar;
use OCA\Viewer\Event\LoadViewer;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\Security\FeaturePolicy\AddFeaturePolicyEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'audioannotations';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(LoadSidebar::class, LoadSidebarListener::class);
		$context->registerEventListener(LoadViewer::class, LoadViewerListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(AddFeaturePolicyEvent::class, SecurityPolicyListener::class);
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, SecurityPolicyListener::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
