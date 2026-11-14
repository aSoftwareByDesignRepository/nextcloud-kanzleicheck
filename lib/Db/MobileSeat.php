<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``kzc_mobile_seat`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getAssignedBy()
 * @method void setAssignedBy(string $assignedBy)
 * @method \DateTimeImmutable getAssignedAt()
 * @method void setAssignedAt(\DateTimeImmutable $assignedAt)
 */
class MobileSeat extends Entity
{
	protected $userId;
	protected $assignedBy;
	protected $assignedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('userId', 'string');
		$this->addType('assignedBy', 'string');
		$this->addType('assignedAt', 'datetime_immutable');
	}
}
