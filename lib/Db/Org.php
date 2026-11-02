<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``ptl_org`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getRef()
 * @method void setRef(?string $ref)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getState()
 * @method void setState(string $state)
 * @method bool getAppOn()
 * @method void setAppOn(bool $appOn)
 * @method ?\DateTimeImmutable getAppOnAt()
 * @method void setAppOnAt(?\DateTimeImmutable $appOnAt)
 * @method ?int getFolderId()
 * @method void setFolderId(?int $folderId)
 * @method ?string getFolderPath()
 * @method void setFolderPath(?string $folderPath)
 * @method ?int getPcCustId()
 * @method void setPcCustId(?int $pcCustId)
 * @method ?string getBookUrl()
 * @method void setBookUrl(?string $bookUrl)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method \DateTimeImmutable getUpdatedAt()
 * @method void setUpdatedAt(\DateTimeImmutable $updatedAt)
 */
class Org extends Entity
{
	protected $name;
	protected $ref;
	protected $kind;
	protected $state;
	protected $appOn;
	protected $appOnAt;
	protected $folderId;
	protected $folderPath;
	protected $pcCustId;
	protected $bookUrl;
	protected $createdBy;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('name', 'string');
		$this->addType('ref', 'string');
		$this->addType('kind', 'string');
		$this->addType('state', 'string');
		$this->addType('appOn', 'boolean');
		$this->addType('appOnAt', 'datetime_immutable');
		$this->addType('folderId', 'integer');
		$this->addType('folderPath', 'string');
		$this->addType('pcCustId', 'integer');
		$this->addType('bookUrl', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('updatedAt', 'datetime_immutable');
	}
}
