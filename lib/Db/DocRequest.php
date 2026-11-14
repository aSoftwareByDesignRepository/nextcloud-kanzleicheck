<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``kzc_req`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOrgId()
 * @method void setOrgId(int $orgId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method ?string getNote()
 * @method void setNote(?string $note)
 * @method ?\DateTimeImmutable getDeadline()
 * @method void setDeadline(?\DateTimeImmutable $deadline)
 * @method string getState()
 * @method void setState(string $state)
 * @method ?int getTaskId()
 * @method void setTaskId(?int $taskId)
 * @method ?int getTemplateId()
 * @method void setTemplateId(?int $templateId)
 * @method ?string getPeriodKey()
 * @method void setPeriodKey(?string $periodKey)
 * @method string getReminderPolicy()
 * @method void setReminderPolicy(string $reminderPolicy)
 * @method int getRemindersSent()
 * @method void setRemindersSent(int $remindersSent)
 * @method ?\DateTimeImmutable getLastReminderAt()
 * @method void setLastReminderAt(?\DateTimeImmutable $lastReminderAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method ?\DateTimeImmutable getClosedAt()
 * @method void setClosedAt(?\DateTimeImmutable $closedAt)
 */
class DocRequest extends Entity
{
	protected $orgId;
	protected $title;
	protected $note;
	protected $deadline;
	protected $state;
	protected $taskId;
	protected $templateId;
	protected $periodKey;
	protected $reminderPolicy;
	protected $remindersSent;
	protected $lastReminderAt;
	protected $createdBy;
	protected $createdAt;
	protected $closedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('orgId', 'integer');
		$this->addType('title', 'string');
		$this->addType('note', 'string');
		$this->addType('deadline', 'datetime_immutable');
		$this->addType('state', 'string');
		$this->addType('taskId', 'integer');
		$this->addType('templateId', 'integer');
		$this->addType('periodKey', 'string');
		$this->addType('reminderPolicy', 'string');
		$this->addType('remindersSent', 'integer');
		$this->addType('lastReminderAt', 'datetime_immutable');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('closedAt', 'datetime_immutable');
	}
}
