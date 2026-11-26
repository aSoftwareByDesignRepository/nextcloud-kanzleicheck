<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Db\SyncBindingMapper;
use OCA\KanzleiCheck\Service\AccessDoorService;
use OCA\KanzleiCheck\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AccessDoorService — the single authz authority for the whole app.
 * Every door decision gets a test: platform restriction, door flag,
 * admin/app-admin shortcuts, allowlists, staffUids fan-out hygiene,
 * saveDoor validation + the SYNC_DOOR_BINDINGS_LIVE fail-closed guard.
 */
class AccessDoorServiceTest extends TestCase
{
	private IAppManager&MockObject $apps;
	private IGroupManager&MockObject $groups;
	private IUserManager&MockObject $users;
	private SettingsService&MockObject $settings;
	private SyncBindingMapper&MockObject $syncs;
	private AccessDoorService $svc;

	protected function setUp(): void
	{
		$this->apps = $this->createMock(IAppManager::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->syncs = $this->createMock(SyncBindingMapper::class);
		$this->svc = new AccessDoorService(
			$this->apps, $this->groups, $this->users, $this->settings, $this->syncs,
		);
	}

	private function user(string $uid, bool $enabled = true): IUser&MockObject
	{
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$u->method('isEnabled')->willReturn($enabled);
		return $u;
	}

	private function platformGroups(array $gids): void
	{
		$this->apps->method('getAppInfo')->with('kanzleicheck')
			->willReturn(['groups' => $gids]);
	}

	// ── isRestricted / platformRestrictedGroups ──

	public function testPlatformGroupsAndRestriction(): void
	{
		$this->platformGroups(['staff']);
		$this->assertSame(['staff'], $this->svc->platformRestrictedGroups());
		$this->assertTrue($this->svc->isRestricted()); // platform groups ⇒ restricted
	}

	public function testRestrictedViaFlagOnly(): void
	{
		$this->platformGroups([]);
		$this->settings->method('getBool')->with('door_restricted')->willReturn(true);
		$this->assertSame([], $this->svc->platformRestrictedGroups());
		$this->assertTrue($this->svc->isRestricted());
	}

	public function testOpenDoor(): void
	{
		$this->platformGroups([]);
		$this->settings->method('getBool')->willReturn(false);
		$this->assertFalse($this->svc->isRestricted());
	}

	// ── isStaff ──

	public function testIsStaffEmptyUidAndAdmins(): void
	{
		$this->assertFalse($this->svc->isStaff(''));
		$this->groups->method('isAdmin')->willReturnMap([['root', true]]);
		$this->assertTrue($this->svc->isStaff('root')); // NC admin always staff
	}

	public function testIsStaffAppAdminList(): void
	{
		$this->groups->method('isAdmin')->willReturn(false);
		$this->settings->method('getList')->willReturnMap([
			['app_admins', ['boss']],
		]);
		$this->assertTrue($this->svc->isStaff('boss'));
	}

	public function testIsStaffOpenDoorEveryone(): void
	{
		$this->platformGroups([]);
		$this->groups->method('isAdmin')->willReturn(false);
		$this->settings->method('getBool')->willReturn(false);
		$this->settings->method('getList')->willReturn([]);
		$this->assertTrue($this->svc->isStaff('anyone')); // Open door
	}

	public function testIsStaffRestrictedLists(): void
	{
		$this->platformGroups(['pgroup']);
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturnMap([
			['alice', 'pgroup', true],
			['bob', 'allowed', true],
		]);
		$this->settings->method('getList')->willReturnMap([
			['allowed_groups', ['allowed']],
			['allowed_users', ['carol']],
			['app_admins', []],
		]);
		$this->settings->method('getBool')->willReturn(true);
		$this->assertTrue($this->svc->isStaff('alice')); // platform group
		$this->assertTrue($this->svc->isStaff('bob'));   // allowed group
		$this->assertTrue($this->svc->isStaff('carol')); // allowed user
		$this->assertFalse($this->svc->isStaff('dave')); // not admitted
	}

	// ── isAppAdmin / canManageClients ──

	public function testAppAdminAndManageClients(): void
	{
		$this->assertFalse($this->svc->isAppAdmin(''));
		$this->groups->method('isAdmin')->willReturnMap([['root', true]]);
		$this->assertTrue($this->svc->isAppAdmin('root')); // NC admin shortcut
		$this->settings->method('getList')->willReturnMap([
			['app_admins', ['boss']],
		]);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('isAdmin')->willReturn(false);
		$svc = new AccessDoorService($this->apps, $this->groups, $this->users, $this->settings, $this->syncs);
		$this->assertTrue($svc->isAppAdmin('boss'));
		$this->assertFalse($svc->isAppAdmin('nobody'));
		$this->assertSame($svc->isAppAdmin('boss'), $svc->canManageClients('boss'));
	}

	// ── staffUids fan-out hygiene ──

	public function testStaffUidsFiltersDeadAccounts(): void
	{
		$this->platformGroups([]);
		$this->settings->method('getBool')->willReturn(true); // restricted
		$this->settings->method('getList')->willReturnMap([
			['allowed_users', ['live', 'dead', 'gone']],
			['app_admins', ['boss']],
			['allowed_groups', ['crew']],
		]);
		$this->users->method('get')->willReturnMap([
			['live', $this->user('live')],
			['dead', $this->user('dead', false)],   // deactivated → dropped
			['gone', null],                          // deleted → dropped
			['boss', $this->user('boss')],
		]);
		$g = $this->createMock(IGroup::class);
		$g->method('getUsers')->willReturn([
			$this->user('groupie'),
			$this->user('off', false), // deactivated group member dropped
		]);
		$this->groups->method('get')->with('crew')->willReturn($g);
		$uids = $this->svc->staffUids();
		sort($uids);
		$this->assertSame(['boss', 'groupie', 'live'], $uids);
	}

	public function testStaffUidsOpenDoorEnumeration(): void
	{
		$this->platformGroups([]);
		$this->settings->method('getBool')->willReturn(false);
		$this->settings->method('getList')->willReturn([]);
		$this->users->method('search')->with('', 5000)->willReturn([
			$this->user('a'), $this->user('b'), $this->user('c', false),
		]);
		$uids = $this->svc->staffUids();
		sort($uids);
		$this->assertSame(['a', 'b'], $uids); // disabled user excluded
	}

	public function testStaffUidsMissingGroupTolerated(): void
	{
		$this->platformGroups([]);
		$this->settings->method('getBool')->willReturn(true);
		$this->settings->method('getList')->willReturnMap([
			['allowed_users', ['live']],
			['app_admins', []],
			['allowed_groups', ['ghost-group']],
		]);
		$this->users->method('get')->willReturnMap([['live', $this->user('live')]]);
		$this->groups->method('get')->willReturn(null); // group deleted
		$this->assertSame(['live'], $this->svc->staffUids());
	}

	// ── saveDoor ──

	public function testSaveDoorValidatesGidsAndUids(): void
	{
		$this->groups->method('groupExists')->willReturnMap([['ok', true], ['ghost', false]]);
		$this->assertSame(['ok' => false, 'error' => 'group:ghost'],
			$this->svc->saveDoor(true, ['ok', 'ghost'], [], []));

		$this->users->method('userExists')->willReturnMap([['alice', true], ['phantom', false]]);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('groupExists')->willReturn(true);
		$svc = new AccessDoorService($this->apps, $this->groups, $this->users, $this->settings, $this->syncs);
		$this->assertSame(['ok' => false, 'error' => 'user:phantom'],
			$svc->saveDoor(true, [], ['alice', 'phantom'], []));
		// ghost uid in app_admins is validated the same way
		$this->assertSame(['ok' => false, 'error' => 'user:phantom'],
			$svc->saveDoor(true, [], [], ['phantom']));
	}

	public function testSaveDoorOpenBlockedByLiveBindings(): void
	{
		$this->groups->method('groupExists')->willReturn(true);
		$this->users->method('userExists')->willReturn(true);
		$this->syncs->method('countNonTerminal')->willReturn(1);
		// flipping to Open while bindings live → refused, nothing persisted
		$this->settings->expects($this->never())->method('setBool');
		$this->assertSame(['ok' => false, 'error' => 'SYNC_DOOR_BINDINGS_LIVE'],
			$this->svc->saveDoor(false, [], ['alice'], ['alice']));
		// same call restricted=true is fine
		$this->settings = $this->createMock(SettingsService::class);
		$svc = new AccessDoorService($this->apps, $this->groups, $this->users, $this->settings, $this->syncs);
		$this->assertTrue($svc->saveDoor(true, [], ['alice'], ['alice'])['ok']);
	}

	public function testPlatformGroupsForceRestrictionEvenWhenFlagOff(): void
	{
		// platform <groups> in info.xml is a restriction on its own — the
		// door flag being off must not re-open membership to non-members
		$this->platformGroups(['pgroup']);
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturn(false);
		$this->settings->method('getBool')->willReturn(false); // flag OFF
		$this->settings->method('getList')->willReturn([]);
		$this->assertTrue($this->svc->isRestricted());
		$this->assertFalse($this->svc->isStaff('outsider')); // still gated
	}

	public function testPlatformGroupsAreStrvalNormalised(): void
	{
		$this->platformGroups([42, 'staff']);
		$this->assertSame(['42', 'staff'], $this->svc->platformRestrictedGroups());
	}

	public function testStaffUidsMergesPlatformAndAllowedDedupes(): void
	{
		$this->platformGroups(['pg', 'crew']); // 'crew' duplicated in both lists
		$this->settings->method('getBool')->willReturn(true);
		$this->settings->method('getList')->willReturnMap([
			['allowed_users', []],
			['app_admins', []],
			['allowed_groups', ['crew']],
		]);
		$gp = $this->createMock(IGroup::class);
		$gp->method('getUsers')->willReturn([$this->user('pm')]);
		$gc = $this->createMock(IGroup::class);
		$gc->method('getUsers')->willReturn([$this->user('cm'), $this->user('cm2', false)]);
		$this->groups->expects($this->exactly(2))->method('get')
			->willReturnMap([['pg', $gp], ['crew', $gc]]); // dedupe: 'crew' once
		$uids = $this->svc->staffUids();
		sort($uids);
		$this->assertSame(['cm', 'pm'], $uids); // merged, deduped, disabled filtered
	}

	public function testStaffUidsRestrictedEmptyStaysEmpty(): void
	{
		// restricted door + nobody admitted → NO open-door enumeration
		$this->platformGroups([]);
		$this->settings->method('getBool')->willReturn(true);
		$this->settings->method('getList')->willReturn([]);
		$this->groups->method('get')->willReturn(null);
		$this->users->expects($this->never())->method('search');
		$this->assertSame([], $this->svc->staffUids());
	}

	public function testSaveDoorOpenWithZeroBindingsAllowed(): void
	{
		$this->groups->method('groupExists')->willReturn(true);
		$this->users->method('userExists')->willReturn(true);
		$this->syncs->method('countNonTerminal')->willReturn(0); // terminal rows only
		$this->settings->expects($this->once())->method('setBool')->with('door_restricted', false);
		$this->assertSame(['ok' => true, 'error' => ''],
			$this->svc->saveDoor(false, [], ['alice'], []));
	}

	public function testSaveDoorNormalisesIntValues(): void
	{
		$this->groups->method('groupExists')->willReturn(true);
		$this->users->method('userExists')->willReturn(true);
		$this->syncs->method('countNonTerminal')->willReturn(0);
		$this->settings->expects($this->exactly(6))->method('setList')
			->willReturnCallback(function (string $k, array $v) {
				foreach ($v as $item) {
					TestCase::assertIsString($item, $k); // strval applied
				}
			});
		$this->svc->saveDoor(true, [7], ['alice'], []);
		$this->svc->saveDoor(true, [], [42], [9]); // ints in users/app_admins too
	}

	public function testSaveDoorPersistsDedupedLists(): void
	{
		$this->groups->method('groupExists')->willReturn(true);
		$this->users->method('userExists')->willReturn(true);
		$this->syncs->method('countNonTerminal')->willReturn(0);
		$this->settings->expects($this->once())->method('setBool')->with('door_restricted', true);
		$this->settings->expects($this->exactly(3))->method('setList')
			->willReturnCallback(function (string $k, array $v) {
				static $expect = [
					'allowed_groups' => ['g1', 'g2'],
					'allowed_users' => ['alice', 'bob'],
					'app_admins' => ['boss'],
				];
				TestCase::assertSame($expect[$k], $v, $k);
			});
		// duplicates + int-ish values normalised
		$this->svc->saveDoor(true, ['g1', 'g1', 'g2'], ['alice', 'bob', 'alice'], ['boss']);
	}
}
