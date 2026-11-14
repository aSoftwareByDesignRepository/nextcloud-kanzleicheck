<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``kzc_approval`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOrgId()
 * @method void setOrgId(int $orgId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getFileSha256()
 * @method void setFileSha256(string $fileSha256)
 * @method string getFileEtag()
 * @method void setFileEtag(string $fileEtag)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method ?int getRequiredIdentityId()
 * @method void setRequiredIdentityId(?int $requiredIdentityId)
 * @method ?\DateTimeImmutable getDeadline()
 * @method void setDeadline(?\DateTimeImmutable $deadline)
 * @method ?string getNote()
 * @method void setNote(?string $note)
 * @method string getState()
 * @method void setState(string $state)
 * @method ?string getDecidedBy()
 * @method void setDecidedBy(?string $decidedBy)
 * @method ?\DateTimeImmutable getDecidedAt()
 * @method void setDecidedAt(?\DateTimeImmutable $decidedAt)
 * @method ?string getReason()
 * @method void setReason(?string $reason)
 * @method ?string getConfirmMethod()
 * @method void setConfirmMethod(?string $confirmMethod)
 * @method ?int getOtpId()
 * @method void setOtpId(?int $otpId)
 * @method string getRequestedBy()
 * @method void setRequestedBy(string $requestedBy)
 * @method \DateTimeImmutable getRequestedAt()
 * @method void setRequestedAt(\DateTimeImmutable $requestedAt)
 */
class Approval extends Entity
{
	protected $orgId;
	protected $fileId;
	protected $fileSha256;
	protected $fileEtag;
	protected $fileName;
	protected $requiredIdentityId;
	protected $deadline;
	protected $note;
	protected $state;
	protected $decidedBy;
	protected $decidedAt;
	protected $reason;
	protected $confirmMethod;
	protected $otpId;
	protected $requestedBy;
	protected $requestedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('orgId', 'integer');
		$this->addType('fileId', 'integer');
		$this->addType('fileSha256', 'string');
		$this->addType('fileEtag', 'string');
		$this->addType('fileName', 'string');
		$this->addType('requiredIdentityId', 'integer');
		$this->addType('deadline', 'datetime_immutable');
		$this->addType('note', 'string');
		$this->addType('state', 'string');
		$this->addType('decidedBy', 'string');
		$this->addType('decidedAt', 'datetime_immutable');
		$this->addType('reason', 'string');
		$this->addType('confirmMethod', 'string');
		$this->addType('otpId', 'integer');
		$this->addType('requestedBy', 'string');
		$this->addType('requestedAt', 'datetime_immutable');
	}
}
