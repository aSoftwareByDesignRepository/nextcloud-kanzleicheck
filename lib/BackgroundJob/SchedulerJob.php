<?php

declare(strict_types=1);

namespace OCA\PortalCheck\BackgroundJob;

use OCA\PortalCheck\Db\ApprovalMapper;
use OCA\PortalCheck\Db\DocRequestMapper;
use OCA\PortalCheck\Db\IdentityMapper;
use OCA\PortalCheck\Db\JobCursorMapper;
use OCA\PortalCheck\Db\MemberMapper;
use OCA\PortalCheck\Db\NotifyMapper;
use OCA\PortalCheck\Service\DocumentService;
use OCA\PortalCheck\Db\ReqItemMapper;
use OCA\PortalCheck\Db\ReqRemindMapper;
use OCA\PortalCheck\Db\ReqTemplateMapper;
use OCA\PortalCheck\Db\ReqTplOrgMapper;
use OCA\PortalCheck\Db\OrgMapper;
use OCA\PortalCheck\Service\ActorResolver;
use OCA\PortalCheck\Service\MailOutboxService;
use OCA\PortalCheck\Service\RequestService;
use OCA\PortalCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/**
 * Scheduler (ZF-06): hourly TimedJob, NC lock, 30 s budget, persisted cursor.
 * Work: recurring templates → requests (idempotent per period), reminders
 * (ptl_req_remind claim before outbox — ZF-03), approval void sweep,
 * pending_kinds mail flush.
 */
class SchedulerJob extends TimedJob
{
	public const BUDGET_SECONDS = 30;
	public const BATCH = 200;

	public function __construct(
		ITimeFactory $time,
		private readonly ILockingProvider $lock,
		private readonly JobCursorMapper $cursors,
		private readonly ReqTemplateMapper $tpls,
		private readonly ReqTplOrgMapper $tplOrgs,
		private readonly DocRequestMapper $requests,
		private readonly ReqItemMapper $items,
		private readonly ReqRemindMapper $reminds,
		private readonly ApprovalMapper $approvals,
		private readonly MemberMapper $members,
		private readonly IdentityMapper $identities,
		private readonly NotifyMapper $notify,
		private readonly OrgMapper $orgs,
		private readonly RequestService $requestService,
		private readonly MailOutboxService $outbox,
		private readonly SettingsService $settings,
		private readonly IURLGenerator $urls,
		private readonly LoggerInterface $logger,
private readonly DocumentService $docs,
		private readonly IDBConnection $db,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void
	{
		try {
			$this->lock->acquireLock('portalcheck_scheduler', ILockingProvider::LOCK_EXCLUSIVE, 'portalcheck scheduler');
		} catch (\Throwable) {
			return; // another node owns it
		}
		try {
			$deadline = microtime(true) + self::BUDGET_SECONDS;
			$this->runIntake($deadline);
			$this->runRecurrences($deadline);
			$this->runReminders($deadline);
			$this->runApprovalVoid($deadline);
			$this->flushPendingMails();
			$this->cursors->set('scheduler', (string)time());
		} finally {
			$this->lock->releaseLock('portalcheck_scheduler', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function runIntake(float $deadline): void
	{
		try {
			\OCP\Server::get(\OCA\PortalCheck\Service\IntakeMailService::class)->poll(50);
		} catch (\Throwable $e) {
			$this->logger->warning('portalcheck intake poll failed', ['e' => $e->getMessage()]);
		}
	}

	private function runRecurrences(float $deadline): void
	{
		$today = new \DateTimeImmutable('today');
		foreach ($this->tpls->findRecurring() as $tpl) {
			if (microtime(true) > $deadline) {
				return;
			}
			$period = $this->currentPeriod($tpl->getRecurrence(), $tpl->getRecurrenceDay(), $tpl->getRecurrenceMonth(), $today);
			if ($period === null) {
				continue;
			}
			$tplItems = json_decode((string)$tpl->getItemsJson(), true) ?: [];
			if ($tplItems === []) {
				continue;
			}
			foreach ($this->tplOrgs->orgIdsFor((int)$tpl->getId()) as $orgId) {
				if (microtime(true) > $deadline) {
					return;
				}
				$org = $this->orgs->find($orgId);
				if ($org === null || $org->getState() !== 'active') {
					continue;
				}
				if ($this->requests->findByPeriod((int)$tpl->getId(), $orgId, $period) !== null) {
					continue; // NN-P19 — UNIQUE backs it too
				}
				$items = array_map(static fn($i) => ['label' => $i['label'], 'hint' => $i['hint'] ?? null, 'kind' => $i['kind'] ?? 'upload'], $tplItems);
				$offset = $tpl->getDeadlineOffsetDays();
				try {
					$this->requestService->create(ActorResolver::system('recurrence'), $orgId, [
						'title' => $tpl->getName(),
						'deadline' => $offset !== null ? $today->modify('+' . (int)$offset . ' days')->format('Y-m-d') : null,
						'items' => $items,
						'templateId' => (int)$tpl->getId(),
						'periodKey' => $period,
						'reminderPolicy' => $tpl->getReminderPolicy(),
					]);
				} catch (\Throwable $e) {
					$this->logger->info('portalcheck recurrence skipped', ['tpl' => (int)$tpl->getId(), 'org' => $orgId]);
				}
			}
		}
	}

	private function currentPeriod(string $rec, ?int $day, ?int $month, \DateTimeImmutable $today): ?string
	{
		return match ($rec) {
			'monthly' => $today->format('Y-m'),
			'quarterly' => $today->format('Y') . '-Q' . (intdiv((int)$today->format('n') - 1, 3) + 1),
			'yearly' => $today->format('Y'),
			default => null,
		};
	}

	private function runReminders(float $deadline): void
	{
		$today = new \DateTimeImmutable('today');
		$cursor = (int)$this->cursors->get('remind_req');
		foreach ($this->requests->findOpenWithDeadline($cursor, self::BATCH) as $r) {
			if (microtime(true) > $deadline) {
				break;
			}
			$this->cursors->set('remind_req', (string)$r->getId());
			if ($r->getRemindersSent() >= 5) {
				continue;
			}
			$due = $this->requestService->dueReminders($r, $today);
			foreach ($due as $ymd) {
				if (!$this->reminds->claim((int)$r->getId(), $ymd)) {
					continue; // already sent/claimed
				}
				foreach ($this->members->findByOrg((int)$r->getOrgId()) as $m) {
					$identity = $this->identities->find((int)$m->getIdentityId());
					if ($identity === null || $identity->getStatus() !== 'active') {
						continue;
					}
					$row = $this->notify->find((int)$m->getIdentityId(), (int)$r->getOrgId());
					if ($row !== null && !(int)$row['mails_enabled']) {
						continue;
					}
					$this->outbox->enqueue($identity->getEmail(), 'remind',
						'remind:' . $r->getId() . ':' . $ymd . ':' . $m->getIdentityId(),
						['deadline' => $r->getDeadline()?->format('d.m.Y'), 'provider' => $this->settings->get('provider_label'), 'portalUrl' => $this->urls->linkToRouteAbsolute('portalcheck.portal.index')],
						$identity->getLocale() ?? 'de');
				}
			}
		}
		// cursor wraps when page is smaller than the batch
	}

	private function runApprovalVoid(float $deadline): void
	{
		$cursor = (int)$this->cursors->get('void_appr');
		$max = $cursor;
		$count = 0;
		foreach ($this->approvals->findPendingPage($cursor, self::BATCH) as $a) {
			if (microtime(true) > $deadline) {
				break;
			}
			$max = max($max, (int)$a->getId());
			$count++;
			try {
				$this->voidCheck($a);
			} catch (\Throwable) {
			}
		}
		if ($count < self::BATCH) {
			$max = 0; // wrapped — restart scan next run
		}
		$this->cursors->set('void_appr', (string)$max);
	}

	private function voidCheck($a): void
	{
		$docs = $this->docs;
		$node = $docs->findNodeById((int)$a->getFileId());
		if ($node === null) {
			$this->void($a, 'deleted');
			return;
		}
		if ((string)$node->getEtag() !== $a->getFileEtag()
			&& !hash_equals((string)$a->getFileSha256(), $docs->hashFile($node))) {
			$this->void($a, 'changed');
		}
	}

	private function void($a, string $reason): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('ptl_approval')
			->set('state', $qb->createNamedParameter('void'))
			->set('reason', $qb->createNamedParameter($reason))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$a->getId())))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('pending')));
		$qb->executeStatement();
	}

	private function flushPendingMails(): void
	{
		foreach ($this->notify->findFlushable(900) as $row) {
			$identity = $this->identities->find((int)$row['identity_id']);
			if ($identity === null || $identity->getStatus() !== 'active') {
				continue;
			}
			if ($this->notify->claimMailSlot((int)$row['identity_id'], (int)$row['org_id'], 900)) {
				$kinds = explode(',', (string)$row['pending_kinds']);
				$what = in_array('message', $kinds, true) ? 'message' : ($kinds[0] ?? 'update');
				$this->outbox->enqueue($identity->getEmail(), 'notice',
					'notice:' . $row['identity_id'] . ':' . $row['org_id'] . ':' . time(),
					['what' => $what, 'provider' => $this->settings->get('provider_label'), 'portalUrl' => $this->urls->linkToRouteAbsolute('portalcheck.portal.index')],
					$identity->getLocale() ?? 'de');
			}
		}
	}
}
