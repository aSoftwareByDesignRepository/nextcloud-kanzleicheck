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

		// ── Config & data transfer (test → production) ──────────────────
		$context->registerService(\OCA\PortalCheck\ConfigTransfer\TransferManifest::class, fn () => new \OCA\PortalCheck\ConfigTransfer\TransferManifest());
		$context->registerService(\OCA\PortalCheck\ConfigTransfer\TransferHooks::class, function ($c): \OCA\PortalCheck\ConfigTransfer\TransferHooks {
			return new \OCA\PortalCheck\ConfigTransfer\TransferHooks(
				$c->get(\OCA\PortalCheck\Service\SettingsService::class),
				$c->get(\OCA\PortalCheck\Service\AccessDoorService::class),
				$c->get(\OCA\PortalCheck\Service\AuditService::class),
				$c->get(\OCA\PortalCheck\Db\SyncBindingMapper::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\PortalCheck\ConfigTransfer\TransferPendingStore::class, fn ($c) => new \OCA\PortalCheck\ConfigTransfer\TransferPendingStore($c->get(IRootFolder::class), $c->get(\OCP\IConfig::class), 'portalcheck'));
		$context->registerService(\OCA\PortalCheck\ConfigTransfer\TransferReceiptStore::class, fn ($c) => new \OCA\PortalCheck\ConfigTransfer\TransferReceiptStore($c->get(\OCP\IConfig::class), 'portalcheck'));
		$context->registerService(\OCA\PortalCheck\ConfigTransfer\TransferService::class, function ($c): \OCA\PortalCheck\ConfigTransfer\TransferService {
			return new \OCA\PortalCheck\ConfigTransfer\TransferService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(IRootFolder::class),
				$c->get(\OCP\App\IAppManager::class),
				$c->get(\OCP\Lock\ILockingProvider::class),
				$c->get(\OCP\IURLGenerator::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(\OCA\PortalCheck\ConfigTransfer\TransferManifest::class),
				$c->get(\OCA\PortalCheck\ConfigTransfer\TransferHooks::class),
				$c->get(\OCA\PortalCheck\ConfigTransfer\TransferPendingStore::class),
				$c->get(\OCA\PortalCheck\ConfigTransfer\TransferReceiptStore::class),
				'portalcheck',
				$c->get(\OCP\IAppConfig::class),
			);
		});
		$context->registerService(\OCA\PortalCheck\BackgroundJob\TransferApplyJob::class, function ($c): \OCA\PortalCheck\BackgroundJob\TransferApplyJob {
			return new \OCA\PortalCheck\BackgroundJob\TransferApplyJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(\OCA\PortalCheck\ConfigTransfer\TransferService::class),
				$c->get(\OCP\Lock\ILockingProvider::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\PortalCheck\Controller\TransferApiController::class, function ($c): \OCA\PortalCheck\Controller\TransferApiController {
			return new \OCA\PortalCheck\Controller\TransferApiController(
				'portalcheck',
				$c->get(\OCP\IRequest::class),
				$c->get(\OCA\PortalCheck\ConfigTransfer\TransferHooks::class),
				$c->get(\OCA\PortalCheck\ConfigTransfer\TransferService::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(\OCP\Security\Bruteforce\IThrottler::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(\OCP\IUserSession::class),
			);
		});
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
