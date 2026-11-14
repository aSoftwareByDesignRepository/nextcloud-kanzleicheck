<?php

declare(strict_types=1);

namespace OCA\KanzleiCheck\Notification;

use OCA\KanzleiCheck\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/** Staff NC notifications — fixed localized subjects, orgId only (§9.8, NN-P04). */
class Notifier implements INotifier
{
	public function __construct(private readonly IFactory $l10n, private readonly IURLGenerator $urls)
	{
	}

	public function getID(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return $this->l10n->get(Application::APP_ID)->t('KanzleiCheck');
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException('Unknown app');
		}
		$l = $this->l10n->get(Application::APP_ID, $languageCode);
		$text = match ($notification->getSubject()) {
			'portal_message' => $l->t('New client message'),
			'portal_upload' => $l->t('New upload'),
			'portal_sync_break' => $l->t('Desktop sync problem'),
			default => $l->t('Portal update'),
		};
		$notification->setParsedSubject($text)
			->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')));
		return $notification;
	}
}
