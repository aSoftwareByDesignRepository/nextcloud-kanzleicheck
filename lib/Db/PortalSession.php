<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``ptl_session`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getTokenHash()
 * @method void setTokenHash(string $tokenHash)
 * @method int getIdentityId()
 * @method void setIdentityId(int $identityId)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method \DateTimeImmutable getExpiresAt()
 * @method void setExpiresAt(\DateTimeImmutable $expiresAt)
 * @method \DateTimeImmutable getAbsExpiresAt()
 * @method void setAbsExpiresAt(\DateTimeImmutable $absExpiresAt)
 * @method \DateTimeImmutable getLastSeenAt()
 * @method void setLastSeenAt(\DateTimeImmutable $lastSeenAt)
 * @method ?string getUaShort()
 * @method void setUaShort(?string $uaShort)
 * @method ?\DateTimeImmutable getRevokedAt()
 * @method void setRevokedAt(?\DateTimeImmutable $revokedAt)
 */
class PortalSession extends Entity
{
	protected $tokenHash;
	protected $identityId;
	protected $createdAt;
	protected $expiresAt;
	protected $absExpiresAt;
	protected $lastSeenAt;
	protected $uaShort;
	protected $revokedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('tokenHash', 'string');
		$this->addType('identityId', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('expiresAt', 'datetime_immutable');
		$this->addType('absExpiresAt', 'datetime_immutable');
		$this->addType('lastSeenAt', 'datetime_immutable');
		$this->addType('uaShort', 'string');
		$this->addType('revokedAt', 'datetime_immutable');
	}
}
