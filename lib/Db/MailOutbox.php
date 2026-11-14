<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``kzc_mail_outbox`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getIdemKey()
 * @method void setIdemKey(string $idemKey)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getToHash()
 * @method void setToHash(string $toHash)
 * @method ?string getToEnc()
 * @method void setToEnc(?string $toEnc)
 * @method ?string getLocale()
 * @method void setLocale(?string $locale)
 * @method ?string getParamsJson()
 * @method void setParamsJson(?string $paramsJson)
 * @method bool getDiscard()
 * @method void setDiscard(bool $discard)
 * @method string getState()
 * @method void setState(string $state)
 * @method int getTries()
 * @method void setTries(int $tries)
 * @method \DateTimeImmutable getNextTryAt()
 * @method void setNextTryAt(\DateTimeImmutable $nextTryAt)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method ?\DateTimeImmutable getSentAt()
 * @method void setSentAt(?\DateTimeImmutable $sentAt)
 */
class MailOutbox extends Entity
{
	protected $idemKey;
	protected $kind;
	protected $toHash;
	protected $toEnc;
	protected $locale;
	protected $paramsJson;
	protected $discard;
	protected $state;
	protected $tries;
	protected $nextTryAt;
	protected $createdAt;
	protected $sentAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('idemKey', 'string');
		$this->addType('kind', 'string');
		$this->addType('toHash', 'string');
		$this->addType('toEnc', 'string');
		$this->addType('locale', 'string');
		$this->addType('paramsJson', 'string');
		$this->addType('discard', 'boolean');
		$this->addType('state', 'string');
		$this->addType('tries', 'integer');
		$this->addType('nextTryAt', 'datetime_immutable');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('sentAt', 'datetime_immutable');
	}
}
