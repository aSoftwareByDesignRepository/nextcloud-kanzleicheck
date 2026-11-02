<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``ptl_audit`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getActor()
 * @method void setActor(string $actor)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method string getTargetType()
 * @method void setTargetType(string $targetType)
 * @method string getTargetId()
 * @method void setTargetId(string $targetId)
 * @method ?string getDetail()
 * @method void setDetail(?string $detail)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 */
class Audit extends Entity
{
	protected $actor;
	protected $action;
	protected $targetType;
	protected $targetId;
	protected $detail;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('actor', 'string');
		$this->addType('action', 'string');
		$this->addType('targetType', 'string');
		$this->addType('targetId', 'string');
		$this->addType('detail', 'string');
		$this->addType('createdAt', 'datetime_immutable');
	}
}
