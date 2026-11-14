<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``kzc_member`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getIdentityId()
 * @method void setIdentityId(int $identityId)
 * @method int getOrgId()
 * @method void setOrgId(int $orgId)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 */
class Member extends Entity
{
	protected $identityId;
	protected $orgId;
	protected $role;
	protected $createdAt;
	protected $createdBy;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('identityId', 'integer');
		$this->addType('orgId', 'integer');
		$this->addType('role', 'string');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('createdBy', 'string');
	}
}
