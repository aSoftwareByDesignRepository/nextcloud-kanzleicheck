<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``ptl_upload`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOrgId()
 * @method void setOrgId(int $orgId)
 * @method string getUploader()
 * @method void setUploader(string $uploader)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method int getSizeBytes()
 * @method void setSizeBytes(int $sizeBytes)
 * @method string getSha256()
 * @method void setSha256(string $sha256)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method bool getIsDuplicate()
 * @method void setIsDuplicate(bool $isDuplicate)
 * @method ?string getNote()
 * @method void setNote(?string $note)
 * @method string getState()
 * @method void setState(string $state)
 * @method ?string getReviewedBy()
 * @method void setReviewedBy(?string $reviewedBy)
 * @method ?\DateTimeImmutable getReviewedAt()
 * @method void setReviewedAt(?\DateTimeImmutable $reviewedAt)
 * @method string getIntegrity()
 * @method void setIntegrity(string $integrity)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 */
class Upload extends Entity
{
	protected $orgId;
	protected $uploader;
	protected $fileId;
	protected $fileName;
	protected $sizeBytes;
	protected $sha256;
	protected $source;
	protected $isDuplicate;
	protected $note;
	protected $state;
	protected $reviewedBy;
	protected $reviewedAt;
	protected $integrity;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('orgId', 'integer');
		$this->addType('uploader', 'string');
		$this->addType('fileId', 'integer');
		$this->addType('fileName', 'string');
		$this->addType('sizeBytes', 'integer');
		$this->addType('sha256', 'string');
		$this->addType('source', 'string');
		$this->addType('isDuplicate', 'boolean');
		$this->addType('note', 'string');
		$this->addType('state', 'string');
		$this->addType('reviewedBy', 'string');
		$this->addType('reviewedAt', 'datetime_immutable');
		$this->addType('integrity', 'string');
		$this->addType('createdAt', 'datetime_immutable');
	}
}
