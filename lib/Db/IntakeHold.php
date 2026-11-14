<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``kzc_intake_hold`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOrgId()
 * @method void setOrgId(int $orgId)
 * @method string getMsgHash()
 * @method void setMsgHash(string $msgHash)
 * @method string getSenderHash()
 * @method void setSenderHash(string $senderHash)
 * @method \DateTimeImmutable getReceivedAt()
 * @method void setReceivedAt(\DateTimeImmutable $receivedAt)
 * @method string getState()
 * @method void setState(string $state)
 * @method ?string getReviewedBy()
 * @method void setReviewedBy(?string $reviewedBy)
 * @method ?\DateTimeImmutable getReviewedAt()
 * @method void setReviewedAt(?\DateTimeImmutable $reviewedAt)
 */
class IntakeHold extends Entity
{
	protected $orgId;
	protected $msgHash;
	protected $senderHash;
	protected $receivedAt;
	protected $state;
	protected $reviewedBy;
	protected $reviewedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('orgId', 'integer');
		$this->addType('msgHash', 'string');
		$this->addType('senderHash', 'string');
		$this->addType('receivedAt', 'datetime_immutable');
		$this->addType('state', 'string');
		$this->addType('reviewedBy', 'string');
		$this->addType('reviewedAt', 'datetime_immutable');
	}
}
