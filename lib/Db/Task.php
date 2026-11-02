<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``ptl_task`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOrgId()
 * @method void setOrgId(int $orgId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method ?string getNote()
 * @method void setNote(?string $note)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getPriority()
 * @method void setPriority(string $priority)
 * @method ?\DateTimeImmutable getDueDate()
 * @method void setDueDate(?\DateTimeImmutable $dueDate)
 * @method ?\DateTimeImmutable getDoneAt()
 * @method void setDoneAt(?\DateTimeImmutable $doneAt)
 * @method string getPortalVisibility()
 * @method void setPortalVisibility(string $portalVisibility)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method ?string getAssignee()
 * @method void setAssignee(?string $assignee)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method \DateTimeImmutable getUpdatedAt()
 * @method void setUpdatedAt(\DateTimeImmutable $updatedAt)
 */
class Task extends Entity
{
	protected $orgId;
	protected $title;
	protected $note;
	protected $status;
	protected $priority;
	protected $dueDate;
	protected $doneAt;
	protected $portalVisibility;
	protected $revision;
	protected $createdBy;
	protected $assignee;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('orgId', 'integer');
		$this->addType('title', 'string');
		$this->addType('note', 'string');
		$this->addType('status', 'string');
		$this->addType('priority', 'string');
		$this->addType('dueDate', 'datetime_immutable');
		$this->addType('doneAt', 'datetime_immutable');
		$this->addType('portalVisibility', 'string');
		$this->addType('revision', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('assignee', 'string');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('updatedAt', 'datetime_immutable');
	}
}
