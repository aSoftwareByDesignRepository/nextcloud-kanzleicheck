<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Staff access door (US-P04, NN-P01, mirrored-config rule §8).
 *
 * Read authority: the NC app restriction ("Limit to groups" on the Apps
 * page) wins on every read — getAppRestriction() is consulted first and a
 * non-empty platform restriction means Restricted even if the app's own
 * flag says Open (fail closed; azc issue #46). The local snapshot keys are
 * write-only for form round-trips.
 *
 * Roles: staff (admitted by door) · app admin (extra list + SYSADMIN always).
 */
class AccessDoorService
{
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly SettingsService $settings,
		private readonly ?\OCA\PortalCheck\Db\SyncBindingMapper $syncBindings = null,
	) {
	}

	/**
	 * Effective restricted-groups: platform restriction is the only read
	 * authority (US-P04 AC-5). Returns [] when unrestricted.
	 * @return list<string>
	 */
	public function platformRestrictedGroups(): array
	{
		$info = $this->appManager->getAppInfo(Application::APP_ID);
		$restriction = is_array($info) ? ($info['groups'] ?? []) : [];
		return is_array($restriction) ? array_values(array_map('strval', $restriction)) : [];
	}

	public function isRestricted(): bool
	{
		return $this->platformRestrictedGroups() !== [] || $this->settings->getBool('door_restricted');
	}

	/** Every admitted staff uid — platform groups ∪ allowed groups ∪ allowed users ∪ app admins. */
	public function isStaff(string $uid): bool
	{
		if ($uid === '') {
			return false;
		}
		if ($this->groupManager->isAdmin($uid) || $this->isAppAdmin($uid)) {
			return true;
		}
		$platform = $this->platformRestrictedGroups();
		if ($platform === [] && !$this->settings->getBool('door_restricted')) {
			return true; // Open door: every signed-in NC user is staff
		}
		foreach ($platform as $gid) {
			if ($this->groupManager->isInGroup($uid, $gid)) {
				return true;
			}
		}
		foreach ($this->settings->getList('allowed_groups') as $gid) {
			if ($this->groupManager->isInGroup($uid, $gid)) {
				return true;
			}
		}
		return in_array($uid, $this->settings->getList('allowed_users'), true);
	}

	public function isAppAdmin(string $uid): bool
	{
		if ($uid === '') {
			return false;
		}
		if ($this->groupManager->isAdmin($uid)) {
			return true;
		}
		return in_array($uid, $this->settings->getList('app_admins'), true);
	}

	/**
	 * Every door-admitted staff uid (platform groups ∪ allowed groups ∪
	 * allowed users ∪ app admins; Open door → all NC users). Used only for
	 * notification fan-out — never for the access decision itself.
	 * @return list<string>
	 */
	public function staffUids(): array
	{
		$uids = [];
		foreach ($this->settings->getList('allowed_users') as $u) {
			$uids[$u] = true;
		}
		foreach ($this->settings->getList('app_admins') as $u) {
			$uids[$u] = true;
		}
		$gids = array_merge($this->platformRestrictedGroups(), $this->settings->getList('allowed_groups'));
		foreach (array_unique($gids) as $gid) {
			try {
				$group = $this->groupManager->get($gid);
				foreach ($group?->getUsers() ?? [] as $u) {
					$uids[$u->getUID()] = true;
				}
			} catch (\Throwable $e) {
				\OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning('portalcheck group enumeration failed', ['gid' => $gid, 'e' => $e->getMessage()]);
			}
		}
		if ($uids === [] && !$this->isRestricted()) {
			// Open door: every NC user is staff — enumerate
			foreach ($this->userManager->search('', 5000) as $u) {
				$uids[$u->getUID()] = true;
			}
		}
		return array_keys($uids);
	}

	/** Client-management right = app admin for v1 (no finer ACL — US-P04 out of scope). */
	public function canManageClients(string $uid): bool
	{
		return $this->isAppAdmin($uid);
	}

	/**
	 * Persist door config. Validates every gid/uid exists (userExists AND
	 * groupExists — the ghost-member hole, api-security §2). The last App
	 * Admin cannot be removed (SYSADMIN remains anyway, AC-4).
	 * @return array{ok:bool,error:string}
	 */
	public function saveDoor(bool $restricted, array $groups, array $users, array $appAdmins): array
	{
		foreach ($groups as $gid) {
			if ($gid === '' || !$this->groupManager->groupExists($gid)) {
				return ['ok' => false, 'error' => 'group:' . $gid];
			}
		}
		foreach (array_merge($users, $appAdmins) as $uid) {
			if ($uid === '' || !$this->userManager->userExists($uid)) {
				return ['ok' => false, 'error' => 'user:' . $uid];
			}
		}
		// D-B12/NN-10: the fail-closed guard applies at BOTH write edges —
		// flipping to Open while non-terminal sync bindings exist would make
		// every bound client uid staff instantly.
		if (!$restricted && $this->syncBindings !== null && $this->syncBindings->countNonTerminal() > 0) {
			return ['ok' => false, 'error' => 'SYNC_DOOR_BINDINGS_LIVE'];
		}
		$this->settings->setBool('door_restricted', $restricted);
		$this->settings->setList('allowed_groups', array_values(array_unique(array_map('strval', $groups))));
		$this->settings->setList('allowed_users', array_values(array_unique(array_map('strval', $users))));
		$this->settings->setList('app_admins', array_values(array_unique(array_map('strval', $appAdmins))));
		return ['ok' => true, 'error' => ''];
	}
}
