<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``ptl_ulink`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getOrgId()
 * @method void setOrgId(int $orgId)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string getTokenHash()
 * @method void setTokenHash(string $tokenHash)
 * @method \DateTimeImmutable getExpiresAt()
 * @method void setExpiresAt(\DateTimeImmutable $expiresAt)
 * @method int getMaxFiles()
 * @method void setMaxFiles(int $maxFiles)
 * @method int getUsedFiles()
 * @method void setUsedFiles(int $usedFiles)
 * @method int getMaxBytes()
 * @method void setMaxBytes(int $maxBytes)
 * @method int getUsedBytes()
 * @method void setUsedBytes(int $usedBytes)
 * @method bool getShowOrg()
 * @method void setShowOrg(bool $showOrg)
 * @method ?\DateTimeImmutable getRevokedAt()
 * @method void setRevokedAt(?\DateTimeImmutable $revokedAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 */
class UploadLink extends Entity
{
	protected $orgId;
	protected $label;
	protected $tokenHash;
	protected $expiresAt;
	protected $maxFiles;
	protected $usedFiles;
	protected $maxBytes;
	protected $usedBytes;
	protected $showOrg;
	protected $revokedAt;
	protected $createdBy;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('orgId', 'integer');
		$this->addType('label', 'string');
		$this->addType('tokenHash', 'string');
		$this->addType('expiresAt', 'datetime_immutable');
		$this->addType('maxFiles', 'integer');
		$this->addType('usedFiles', 'integer');
		$this->addType('maxBytes', 'integer');
		$this->addType('usedBytes', 'integer');
		$this->addType('showOrg', 'boolean');
		$this->addType('revokedAt', 'datetime_immutable');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime_immutable');
	}
}
