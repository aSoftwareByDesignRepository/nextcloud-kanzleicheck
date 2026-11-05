<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\AppInfo;

use OCA\PortalCheck\Middleware\ApiExceptionMiddleware;
use OCA\PortalCheck\Middleware\DeviceAuthMiddleware;
use OCA\PortalCheck\Middleware\PortalSessionMiddleware;
use OCA\PortalCheck\Middleware\RateLimitMiddleware;
use OCA\PortalCheck\Middleware\StaffAccessMiddleware;
use OCA\PortalCheck\Notification\Notifier;
use OCA\PortalCheck\Repair\UninstallDropTables;
use OCA\PortalCheck\Service\AccessDoorService;
use OCA\PortalCheck\Support\NavGate;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\IRootFolder;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'portalcheck';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		$context->registerService(UninstallDropTables::class, function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(IRootFolder::class),
				$c->get(\OCP\Share\IManager::class),
			);
		});
		// afterException iterates in reverse — generic envelope registers FIRST.
		$context->registerMiddleware(ApiExceptionMiddleware::class);
		$context->registerMiddleware(StaffAccessMiddleware::class);
		$context->registerMiddleware(DeviceAuthMiddleware::class);
		$context->registerMiddleware(PortalSessionMiddleware::class);
		$context->registerMiddleware(RateLimitMiddleware::class);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void
	{
		$app = $context->getAppContainer();
		NavGate::register(
			$app,
			'portalcheck.page.index',
			'app.svg',
			'PortalCheck',
			10,
			static fn(string $uid): bool => $app->get(AccessDoorService::class)->isStaff($uid),
		);
	}
}
