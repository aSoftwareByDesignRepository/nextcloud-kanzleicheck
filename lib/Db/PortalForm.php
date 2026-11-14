<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``kzc_form`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOrgId()
 * @method void setOrgId(int $orgId)
 * @method int getTplId()
 * @method void setTplId(int $tplId)
 * @method ?int getReqItemId()
 * @method void setReqItemId(?int $reqItemId)
 * @method string getState()
 * @method void setState(string $state)
 * @method ?string getSubmittedBy()
 * @method void setSubmittedBy(?string $submittedBy)
 * @method ?\DateTimeImmutable getSubmittedAt()
 * @method void setSubmittedAt(?\DateTimeImmutable $submittedAt)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 */
class PortalForm extends Entity
{
	protected $orgId;
	protected $tplId;
	protected $reqItemId;
	protected $state;
	protected $submittedBy;
	protected $submittedAt;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('orgId', 'integer');
		$this->addType('tplId', 'integer');
		$this->addType('reqItemId', 'integer');
		$this->addType('state', 'string');
		$this->addType('submittedBy', 'string');
		$this->addType('submittedAt', 'datetime_immutable');
		$this->addType('createdAt', 'datetime_immutable');
	}
}
