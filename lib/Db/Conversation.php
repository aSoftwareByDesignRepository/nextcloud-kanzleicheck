<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``kzc_conv`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOrgId()
 * @method void setOrgId(int $orgId)
 * @method string getState()
 * @method void setState(string $state)
 * @method ?\DateTimeImmutable getLastMessageAt()
 * @method void setLastMessageAt(?\DateTimeImmutable $lastMessageAt)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 */
class Conversation extends Entity
{
	protected $orgId;
	protected $state;
	protected $lastMessageAt;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('orgId', 'integer');
		$this->addType('state', 'string');
		$this->addType('lastMessageAt', 'datetime_immutable');
		$this->addType('createdAt', 'datetime_immutable');
	}
}
