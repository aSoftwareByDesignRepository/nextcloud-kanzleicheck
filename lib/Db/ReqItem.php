<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``kzc_req_item`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getReqId()
 * @method void setReqId(int $reqId)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method ?string getHint()
 * @method void setHint(?string $hint)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method ?int getFormId()
 * @method void setFormId(?int $formId)
 * @method string getState()
 * @method void setState(string $state)
 * @method ?string getRejectReason()
 * @method void setRejectReason(?string $rejectReason)
 * @method ?string getDeliveredBy()
 * @method void setDeliveredBy(?string $deliveredBy)
 * @method ?\DateTimeImmutable getDeliveredAt()
 * @method void setDeliveredAt(?\DateTimeImmutable $deliveredAt)
 * @method ?string getReviewedBy()
 * @method void setReviewedBy(?string $reviewedBy)
 * @method ?\DateTimeImmutable getReviewedAt()
 * @method void setReviewedAt(?\DateTimeImmutable $reviewedAt)
 */
class ReqItem extends Entity
{
	protected $reqId;
	protected $position;
	protected $label;
	protected $hint;
	protected $kind;
	protected $formId;
	protected $state;
	protected $rejectReason;
	protected $deliveredBy;
	protected $deliveredAt;
	protected $reviewedBy;
	protected $reviewedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('reqId', 'integer');
		$this->addType('position', 'integer');
		$this->addType('label', 'string');
		$this->addType('hint', 'string');
		$this->addType('kind', 'string');
		$this->addType('formId', 'integer');
		$this->addType('state', 'string');
		$this->addType('rejectReason', 'string');
		$this->addType('deliveredBy', 'string');
		$this->addType('deliveredAt', 'datetime_immutable');
		$this->addType('reviewedBy', 'string');
		$this->addType('reviewedAt', 'datetime_immutable');
	}
}
