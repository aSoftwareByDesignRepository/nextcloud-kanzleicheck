<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``kzc_license_state`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getKeyFingerprint()
 * @method void setKeyFingerprint(string $keyFingerprint)
 * @method string getPayloadJson()
 * @method void setPayloadJson(string $payloadJson)
 * @method string getSignatureB64()
 * @method void setSignatureB64(string $signatureB64)
 * @method string getCustomerId()
 * @method void setCustomerId(string $customerId)
 * @method string getValidUntil()
 * @method void setValidUntil(string $validUntil)
 * @method int getMobileSeats()
 * @method void setMobileSeats(int $mobileSeats)
 * @method int getPortalClients()
 * @method void setPortalClients(int $portalClients)
 * @method string getAppliedBy()
 * @method void setAppliedBy(string $appliedBy)
 * @method \DateTimeImmutable getAppliedAt()
 * @method void setAppliedAt(\DateTimeImmutable $appliedAt)
 */
class LicenseState extends Entity
{
	protected $keyFingerprint;
	protected $payloadJson;
	protected $signatureB64;
	protected $customerId;
	protected $validUntil;
	protected $mobileSeats;
	protected $portalClients;
	protected $appliedBy;
	protected $appliedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('keyFingerprint', 'string');
		$this->addType('payloadJson', 'string');
		$this->addType('signatureB64', 'string');
		$this->addType('customerId', 'string');
		$this->addType('validUntil', 'string');
		$this->addType('mobileSeats', 'integer');
		$this->addType('portalClients', 'integer');
		$this->addType('appliedBy', 'string');
		$this->addType('appliedAt', 'datetime_immutable');
	}
}
