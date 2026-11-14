<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``kzc_identity`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string getDisplayName()
 * @method void setDisplayName(string $displayName)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getLocale()
 * @method void setLocale(?string $locale)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method ?\DateTimeImmutable getLastLoginAt()
 * @method void setLastLoginAt(?\DateTimeImmutable $lastLoginAt)
 * @method ?string getNcUid()
 * @method void setNcUid(?string $ncUid)
 * @method ?string getNcUidOrigin()
 * @method void setNcUidOrigin(?string $ncUidOrigin)
 * @method bool getNcUidMismatch()
 * @method void setNcUidMismatch(bool $ncUidMismatch)
 */
class Identity extends Entity
{
	protected $email;
	protected $displayName;
	protected $status;
	protected $locale;
	protected $createdAt;
	protected $lastLoginAt;
	protected $ncUid;
	protected $ncUidOrigin;
	protected $ncUidMismatch;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('email', 'string');
		$this->addType('displayName', 'string');
		$this->addType('status', 'string');
		$this->addType('locale', 'string');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('lastLoginAt', 'datetime_immutable');
		$this->addType('ncUid', 'string');
		$this->addType('ncUidOrigin', 'string');
		$this->addType('ncUidMismatch', 'boolean');
	}
}
