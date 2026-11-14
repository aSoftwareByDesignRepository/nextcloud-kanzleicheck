<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Service;

use OCA\KanzleiCheck\AppInfo\Application;
use OCA\KanzleiCheck\Db\ContactMapper;
use OCA\KanzleiCheck\Db\IdentityMapper;
use OCA\KanzleiCheck\Db\MemberMapper;
use OCA\KanzleiCheck\Db\NotifyMapper;
use OCP\Notification\IManager as INotificationManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Notifications (US-P17, §9.8): staff → NC notification; clients →
 * content-free e-mail via outbox, ≤1/identity/org/15 min with kind batching.
 * No message text, file names, org or person names anywhere (NN-P04).
 */
class NotifyService
{
	public const THROTTLE_SECONDS = 900;

	public function __construct(
		private readonly INotificationManager $notifications,
		private readonly NotifyMapper $notify,
		private readonly MemberMapper $members,
		private readonly IdentityMapper $identities,
		private readonly ContactMapper $contacts,
		private readonly IUserManager $userManager,
		private readonly AccessDoorService $door,
		private readonly MailOutboxService $outbox,
		private readonly SettingsService $settings,
		private readonly IURLGenerator $urls,
		private readonly LoggerInterface $logger,
	) {
	}

	/** Staff NC notification (US-P17 AC-1) — subject carries orgId only. */
	public function staffNotify(string $uid, string $subject, int $orgId, string $link): void
	{
		try {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)->setUser($uid)
				->setDateTime(new \DateTime())
				->setObject('portal_org', (string)$orgId)
				->setSubject($subject, ['orgId' => $orgId])
				->setLink($link);
			$this->notifications->notify($n);
		} catch (\Throwable $e) {
			// best-effort: notification fan-out must never fail the triggering mutation
			$this->logger->warning('kanzleicheck staff notify failed', ['e' => $e->getMessage()]);
		}
	}

	/**
	 * Staff recipients for an org event: responsible staff when configured,
	 * else every door-admitted staff user (US-P27 AC-3 — an org without
	 * responsible staff must never produce a silent notification dead end).
	 */
	public function staffRecipients(int $orgId): array
	{
		$responsible = [];
		foreach ($this->contacts->findForOrg($orgId) as $c) {
			$uid = (string)$c['user_id'];
			$user = $this->userManager->get($uid);
			// Contact rows persist after deactivation/deletion — a dead uid
			// must neither be notified nor suppress the all-staff fallback
			// (inactive_recipient_notification_fanout).
			if ($user === null || !$user->isEnabled()) {
				continue;
			}
			$responsible[] = $uid;
		}
		return $responsible !== [] ? $responsible : $this->door->staffUids();
	}

	/** Client mail for an event kind — throttled per identity+org. */
	public function clientMail(int $orgId, string $what): void
	{
		foreach ($this->members->findByOrg($orgId) as $m) {
			$iid = (int)$m->getIdentityId();
			$identity = $this->identities->find($iid);
			if ($identity === null || $identity->getStatus() !== 'active') {
				continue;
			}
			if ($this->notify->claimMailSlot($iid, $orgId, self::THROTTLE_SECONDS)) {
				$this->outbox->enqueue(
					$identity->getEmail(), 'notice',
					'notice:' . $iid . ':' . $orgId . ':' . time(),
					['what' => $what, 'provider' => $this->settings->get('provider_label'), 'portalUrl' => $this->urls->linkToRouteAbsolute('kanzleicheck.portal.index')],
					$identity->getLocale() ?? 'de'
				);
			} else {
				$this->notify->addPendingKind($iid, $orgId, $what);
			}
		}
	}
}
