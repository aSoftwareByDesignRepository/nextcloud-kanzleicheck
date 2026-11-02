<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``ptl_req_tpl`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getPreset()
 * @method void setPreset(?string $preset)
 * @method string getItemsJson()
 * @method void setItemsJson(string $itemsJson)
 * @method ?int getDeadlineOffsetDays()
 * @method void setDeadlineOffsetDays(?int $deadlineOffsetDays)
 * @method string getReminderPolicy()
 * @method void setReminderPolicy(string $reminderPolicy)
 * @method string getRecurrence()
 * @method void setRecurrence(string $recurrence)
 * @method ?int getRecurrenceDay()
 * @method void setRecurrenceDay(?int $recurrenceDay)
 * @method ?int getRecurrenceMonth()
 * @method void setRecurrenceMonth(?int $recurrenceMonth)
 * @method bool getActive()
 * @method void setActive(bool $active)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 */
class ReqTemplate extends Entity
{
	protected $name;
	protected $preset;
	protected $itemsJson;
	protected $deadlineOffsetDays;
	protected $reminderPolicy;
	protected $recurrence;
	protected $recurrenceDay;
	protected $recurrenceMonth;
	protected $active;
	protected $createdBy;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('name', 'string');
		$this->addType('preset', 'string');
		$this->addType('itemsJson', 'string');
		$this->addType('deadlineOffsetDays', 'integer');
		$this->addType('reminderPolicy', 'string');
		$this->addType('recurrence', 'string');
		$this->addType('recurrenceDay', 'integer');
		$this->addType('recurrenceMonth', 'integer');
		$this->addType('active', 'boolean');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime_immutable');
	}
}
