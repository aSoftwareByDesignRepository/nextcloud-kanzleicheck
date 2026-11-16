<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Tests\Unit;

use OCA\KanzleiCheck\Controller\StaffApiController;
use OCA\KanzleiCheck\Service\AccessDoorService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Staff directory picker must find people by display name on uid-opaque
 * backends (search() matches uid only there) while never offering disabled
 * accounts — searchUsers merges IUserManager::search() ∪ searchDisplayName(),
 * dedupes by uid, filters isEnabled(), caps at 20.
 */
final class StaffSearchUsersMergeTest extends TestCase
{
	private function makeController(IRequest $request, IUserManager $userManager, AccessDoorService $door): StaffApiController
	{
		$rc = new \ReflectionClass(StaffApiController::class);
		$args = [];
		foreach ((array)$rc->getConstructor()?->getParameters() as $p) {
			$t = $p->getType();
			$name = $t instanceof \ReflectionNamedType ? $t->getName() : '';
			$args[] = match ($name) {
				'string' => 'kanzleicheck',
				IRequest::class => $request,
				IUserManager::class => $userManager,
				AccessDoorService::class => $door,
				default => $this->createMock($name),
			};
		}
		return $rc->newInstanceArgs($args);
	}

	private function request(string $q): IRequest
	{
		$r = $this->createMock(IRequest::class);
		$r->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $key === 'q' ? $q : $default
		);
		return $r;
	}

	private function user(string $uid, bool $enabled = true, string $name = '', string $email = ''): IUser
	{
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$u->method('isEnabled')->willReturn($enabled);
		$u->method('getDisplayName')->willReturn($name !== '' ? $name : $uid);
		$u->method('getEMailAddress')->willReturn($email);
		return $u;
	}

	private function manager(array $byUid, array $byName, bool $nameThrows = false): IUserManager
	{
		$m = $this->createMock(IUserManager::class);
		$m->method('search')->willReturn($byUid);
		$m->method('searchDisplayName')->willReturnCallback(
			static function () use ($byName, $nameThrows) {
				if ($nameThrows) {
					throw new \RuntimeException('backend has no display-name index');
				}
				return $byName;
			}
		);
		return $m;
	}

	private function door(array $staffUids = []): AccessDoorService
	{
		$d = $this->createMock(AccessDoorService::class);
		$d->method('isStaff')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, $staffUids, true)
		);
		return $d;
	}

	/** @return list<array{uid:string,displayName:string,email:?string,staff:bool}> */
	private function usersOf(StaffApiController $c): array
	{
		$res = $c->searchUsers();
		$data = $res->getData();
		$this->assertTrue($data['success'] ?? false);
		return $data['data']['users'] ?? [];
	}

	public function testMergesDisplayNameHitsAndDedupesByUid(): void
	{
		$alice = $this->user('u-alice', true, 'Alice Smith');
		$bob = $this->user('u-bob', true, 'Bob Jones');
		$carol = $this->user('u-carol', true, 'Carol Smith');
		// uid backend: alice+bob; display-name backend: alice+carol (carol only by name)
		$c = $this->makeController(
			$this->request('smith'),
			$this->manager([$alice, $bob], [$alice, $carol]),
			$this->door()
		);
		$uids = array_column($this->usersOf($c), 'uid');
		$this->assertSame(['u-alice', 'u-bob', 'u-carol'], $uids);
		$this->assertSame($uids, array_unique($uids));
	}

	public function testDisabledAccountsFilteredFromBothSources(): void
	{
		$c = $this->makeController(
			$this->request('x'),
			$this->manager(
				[$this->user('u-off-uid', false), $this->user('u-on-uid')],
				[$this->user('u-off-name', false), $this->user('u-on-name')]
			),
			$this->door()
		);
		$uids = array_column($this->usersOf($c), 'uid');
		$this->assertSame(['u-on-uid', 'u-on-name'], $uids);
	}

	public function testDisplayNameSearchThrowingDegradesToUidResults(): void
	{
		$c = $this->makeController(
			$this->request('x'),
			$this->manager([$this->user('u-alive')], [], true),
			$this->door()
		);
		$this->assertSame(['u-alive'], array_column($this->usersOf($c), 'uid'));
	}

	public function testResultCapAppliesAcrossTheMerge(): void
	{
		$many = [];
		for ($i = 0; $i < 40; $i++) {
			$many[] = $this->user('u-' . $i);
		}
		$c = $this->makeController($this->request('u'), $this->manager($many, []), $this->door());
		$this->assertCount(20, $this->usersOf($c));
	}

	public function testEmptyAndWhitespaceQueryHitsNoBackend(): void
	{
		$m = $this->createMock(IUserManager::class);
		$m->expects($this->never())->method('search');
		$m->expects($this->never())->method('searchDisplayName');
		$c = $this->makeController($this->request('   '), $m, $this->door());
		$this->assertSame([], $this->usersOf($c));
	}

	public function testEmptyUidRowsAreSkipped(): void
	{
		$c = $this->makeController(
			$this->request('x'),
			$this->manager([$this->user(''), $this->user('u-ok')], []),
			$this->door()
		);
		$this->assertSame(['u-ok'], array_column($this->usersOf($c), 'uid'));
	}

	public function testStaffFlagReflectsDoorMembership(): void
	{
		$c = $this->makeController(
			$this->request('x'),
			$this->manager([$this->user('u-staff'), $this->user('u-plain')], []),
			$this->door(['u-staff'])
		);
		$users = $this->usersOf($c);
		$this->assertTrue($users[0]['staff']);
		$this->assertFalse($users[1]['staff']);
	}
}
