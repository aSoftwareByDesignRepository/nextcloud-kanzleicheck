<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\AppInfo;

use OCA\KanzleiCheck\Middleware\ApiExceptionMiddleware;
use OCA\KanzleiCheck\Middleware\CompanionSeatMiddleware;
use OCA\KanzleiCheck\Middleware\DeviceAuthMiddleware;
use OCA\KanzleiCheck\Middleware\PortalSessionMiddleware;
use OCA\KanzleiCheck\Middleware\RateLimitMiddleware;
use OCA\KanzleiCheck\Middleware\StaffAccessMiddleware;
use OCA\KanzleiCheck\Notification\Notifier;
use OCA\KanzleiCheck\Repair\UninstallDropTables;
use OCA\KanzleiCheck\Service\AccessDoorService;
use OCA\KanzleiCheck\Support\NavGate;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\IRootFolder;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'kanzleicheck';

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
		// seat gate after the door: non-staff gets 403, never a payment oracle
		$context->registerMiddleware(CompanionSeatMiddleware::class);
		$context->registerMiddleware(DeviceAuthMiddleware::class);
		$context->registerMiddleware(PortalSessionMiddleware::class);
		$context->registerMiddleware(RateLimitMiddleware::class);
		$context->registerNotifierService(Notifier::class);

		// ── Config & data transfer (test → production) ──────────────────
		$context->registerService(\OCA\KanzleiCheck\ConfigTransfer\TransferManifest::class, fn () => new \OCA\KanzleiCheck\ConfigTransfer\TransferManifest());
		$context->registerService(\OCA\KanzleiCheck\ConfigTransfer\TransferHooks::class, function ($c): \OCA\KanzleiCheck\ConfigTransfer\TransferHooks {
			return new \OCA\KanzleiCheck\ConfigTransfer\TransferHooks(
				$c->get(\OCA\KanzleiCheck\Service\SettingsService::class),
				$c->get(\OCA\KanzleiCheck\Service\AccessDoorService::class),
				$c->get(\OCA\KanzleiCheck\Service\AuditService::class),
				$c->get(\OCA\KanzleiCheck\Db\SyncBindingMapper::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\KanzleiCheck\ConfigTransfer\TransferPendingStore::class, fn ($c) => new \OCA\KanzleiCheck\ConfigTransfer\TransferPendingStore($c->get(IRootFolder::class), $c->get(\OCP\IConfig::class), 'kanzleicheck', $c->get(\Psr\Log\LoggerInterface::class)));
		$context->registerService(\OCA\KanzleiCheck\ConfigTransfer\TransferReceiptStore::class, fn ($c) => new \OCA\KanzleiCheck\ConfigTransfer\TransferReceiptStore($c->get(\OCP\IConfig::class), 'kanzleicheck'));
		$context->registerService(\OCA\KanzleiCheck\ConfigTransfer\TransferService::class, function ($c): \OCA\KanzleiCheck\ConfigTransfer\TransferService {
			return new \OCA\KanzleiCheck\ConfigTransfer\TransferService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(IRootFolder::class),
				$c->get(\OCP\App\IAppManager::class),
				$c->get(\OCP\Lock\ILockingProvider::class),
				$c->get(\OCP\IURLGenerator::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(\OCA\KanzleiCheck\ConfigTransfer\TransferManifest::class),
				$c->get(\OCA\KanzleiCheck\ConfigTransfer\TransferHooks::class),
				$c->get(\OCA\KanzleiCheck\ConfigTransfer\TransferPendingStore::class),
				$c->get(\OCA\KanzleiCheck\ConfigTransfer\TransferReceiptStore::class),
				'kanzleicheck',
				$c->get(\OCP\IAppConfig::class),
			);
		});
		$context->registerService(\OCA\KanzleiCheck\BackgroundJob\TransferApplyJob::class, function ($c): \OCA\KanzleiCheck\BackgroundJob\TransferApplyJob {
			return new \OCA\KanzleiCheck\BackgroundJob\TransferApplyJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(\OCA\KanzleiCheck\ConfigTransfer\TransferService::class),
				$c->get(\OCP\Lock\ILockingProvider::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\KanzleiCheck\Controller\TransferApiController::class, function ($c): \OCA\KanzleiCheck\Controller\TransferApiController {
			return new \OCA\KanzleiCheck\Controller\TransferApiController(
				'kanzleicheck',
				$c->get(\OCP\IRequest::class),
				$c->get(\OCA\KanzleiCheck\ConfigTransfer\TransferHooks::class),
				$c->get(\OCA\KanzleiCheck\ConfigTransfer\TransferService::class),
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
			'kanzleicheck.page.index',
			'app.svg',
			'KanzleiCheck',
			10,
			static fn(string $uid): bool => $app->get(AccessDoorService::class)->isStaff($uid),
		);
	}
}
