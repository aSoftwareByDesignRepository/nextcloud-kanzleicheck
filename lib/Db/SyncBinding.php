<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PortalCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``ptl_sync`` entity — per-org share binding between a bound identity and an
 * org folder (nc-user-binding spec §9.1, D-B01). The uid lives on the
 * identity (ptl_identity.nc_uid); this row is share state only.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getIdentityId()
 * @method void setIdentityId(int $identityId)
 * @method int getOrgId()
 * @method void setOrgId(int $orgId)
 * @method ?string getShareId()
 * @method void setShareId(?string $shareId)
 * @method ?int getShareNodeId()
 * @method void setShareNodeId(?int $shareNodeId)
 * @method string getSharedBy()
 * @method void setSharedBy(string $sharedBy)
 * @method string getPerms()
 * @method void setPerms(string $perms)
 * @method string getState()
 * @method void setState(string $state)
 * @method ?\DateTimeImmutable getLastVerifyAt()
 * @method void setLastVerifyAt(?\DateTimeImmutable $lastVerifyAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 * @method \DateTimeImmutable getUpdatedAt()
 * @method void setUpdatedAt(\DateTimeImmutable $updatedAt)
 */
class SyncBinding extends Entity
{
	public const ST_ACTIVE = 'active';
	public const ST_PENDING = 'pending_accept';
	public const ST_DISABLED = 'disabled';
	public const ST_BROKEN_USER = 'broken_user';
	public const ST_BROKEN_SHARE = 'broken_share';
	public const ST_BROKEN_PERMS = 'broken_perms';
	public const ST_STAFF_CONFLICT = 'staff_conflict';
	public const ST_REVOKED = 'revoked';

	public const PERMS_RW = 'rw';
	public const PERMS_RO = 'ro';

	/** States that still mean "this binding owns share semantics". */
	public const TERMINAL = [self::ST_REVOKED];

	protected $identityId;
	protected $orgId;
	protected $shareId;
	protected $shareNodeId;
	protected $sharedBy;
	protected $perms;
	protected $state;
	protected $lastVerifyAt;
	protected $createdBy;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('identityId', 'integer');
		$this->addType('orgId', 'integer');
		$this->addType('shareId', 'string');
		$this->addType('shareNodeId', 'integer');
		$this->addType('sharedBy', 'string');
		$this->addType('perms', 'string');
		$this->addType('state', 'string');
		$this->addType('lastVerifyAt', 'datetime_immutable');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('updatedAt', 'datetime_immutable');
	}

	public function isTerminal(): bool
	{
		return in_array($this->getState(), self::TERMINAL, true);
	}
}
