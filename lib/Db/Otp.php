<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``kzc_otp`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string getPurpose()
 * @method void setPurpose(string $purpose)
 * @method string getCodeHash()
 * @method void setCodeHash(string $codeHash)
 * @method ?string getMagicHash()
 * @method void setMagicHash(?string $magicHash)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method \DateTimeImmutable getExpiresAt()
 * @method void setExpiresAt(\DateTimeImmutable $expiresAt)
 * @method ?\DateTimeImmutable getUsedAt()
 * @method void setUsedAt(?\DateTimeImmutable $usedAt)
 * @method ?string getIpHash()
 * @method void setIpHash(?string $ipHash)
 */
class Otp extends Entity
{
	protected $email;
	protected $purpose;
	protected $codeHash;
	protected $magicHash;
	protected $attempts;
	protected $createdAt;
	protected $expiresAt;
	protected $usedAt;
	protected $ipHash;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('email', 'string');
		$this->addType('purpose', 'string');
		$this->addType('codeHash', 'string');
		$this->addType('magicHash', 'string');
		$this->addType('attempts', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('expiresAt', 'datetime_immutable');
		$this->addType('usedAt', 'datetime_immutable');
		$this->addType('ipHash', 'string');
	}
}
