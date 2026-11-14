<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Family rule: staff pickers and staff enumerations never include
 * deactivated Nextcloud accounts — they cannot sign in, receive or act on
 * portal notifications.
 */
final class DisabledAccountGuardContractTest extends TestCase
{
	private function src(string $rel): string
	{
		return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
	}

	public function testStaffSearchSkipsDeactivatedAccounts(): void
	{
		$src = $this->src('lib/Controller/StaffApiController.php');
		$this->assertMatchesRegularExpression(
			'/function searchUsers[\s\S]{0,1500}if \(!\$u->isEnabled\(\)\)/',
			$src,
			'StaffApiController::searchUsers must skip deactivated accounts'
		);
	}

	public function testStaffUidEnumerationSkipsDeactivatedAccounts(): void
	{
		$src = $this->src('lib/Service/AccessDoorService.php');
		$this->assertMatchesRegularExpression(
			'/function staffUids[\s\S]{0,3000}if \(!\$u->isEnabled\(\)\)[\s\S]{0,3000}if \(!\$u->isEnabled\(\)\)/',
			$src,
			'AccessDoorService::staffUids must skip deactivated accounts in group AND open-door enumeration'
		);
	}

	public function testConfiguredStaffListsSkipDeactivatedAccounts(): void
	{
		$src = $this->src('lib/Service/AccessDoorService.php');
		$this->assertMatchesRegularExpression(
			'/function staffUids[\s\S]{0,2000}userManager->get\(\$uid\)[\s\S]{0,200}!\$u->isEnabled\(\)/',
			$src,
			'AccessDoorService::staffUids must drop deactivated/deleted uids from the configured allowed_users/app_admins lists'
		);
	}

	public function testStaffRecipientsFiltersDisabledContactsAndFallsBack(): void
	{
		$src = $this->src('lib/Service/NotifyService.php');
		$this->assertMatchesRegularExpression(
			'/function staffRecipients[\s\S]{0,2000}!\$user->isEnabled\(\)[\s\S]{0,500}staffUids\(\)/',
			$src,
			'NotifyService::staffRecipients must drop disabled/deleted contact uids and fall back to staffUids() when empty'
		);
	}
}
