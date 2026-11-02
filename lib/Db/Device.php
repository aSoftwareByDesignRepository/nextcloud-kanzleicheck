<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``ptl_device`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getIdentityId()
 * @method void setIdentityId(int $identityId)
 * @method string getTokenHash()
 * @method void setTokenHash(string $tokenHash)
 * @method string getDeviceName()
 * @method void setDeviceName(string $deviceName)
 * @method string getPlatform()
 * @method void setPlatform(string $platform)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method \DateTimeImmutable getLastUsedAt()
 * @method void setLastUsedAt(\DateTimeImmutable $lastUsedAt)
 * @method \DateTimeImmutable getAbsExpiresAt()
 * @method void setAbsExpiresAt(\DateTimeImmutable $absExpiresAt)
 * @method ?\DateTimeImmutable getRevokedAt()
 * @method void setRevokedAt(?\DateTimeImmutable $revokedAt)
 */
class Device extends Entity
{
	protected $identityId;
	protected $tokenHash;
	protected $deviceName;
	protected $platform;
	protected $createdAt;
	protected $lastUsedAt;
	protected $absExpiresAt;
	protected $revokedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('identityId', 'integer');
		$this->addType('tokenHash', 'string');
		$this->addType('deviceName', 'string');
		$this->addType('platform', 'string');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('lastUsedAt', 'datetime_immutable');
		$this->addType('absExpiresAt', 'datetime_immutable');
		$this->addType('revokedAt', 'datetime_immutable');
	}
}
