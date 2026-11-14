<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``kzc_message`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getConvId()
 * @method void setConvId(int $convId)
 * @method string getAuthor()
 * @method void setAuthor(string $author)
 * @method string getAuthorKind()
 * @method void setAuthorKind(string $authorKind)
 * @method ?string getClientUuid()
 * @method void setClientUuid(?string $clientUuid)
 * @method ?string getBody()
 * @method void setBody(?string $body)
 * @method ?int getFileId()
 * @method void setFileId(?int $fileId)
 * @method ?int getUploadId()
 * @method void setUploadId(?int $uploadId)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 */
class Message extends Entity
{
	protected $convId;
	protected $author;
	protected $authorKind;
	protected $clientUuid;
	protected $body;
	protected $fileId;
	protected $uploadId;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('convId', 'integer');
		$this->addType('author', 'string');
		$this->addType('authorKind', 'string');
		$this->addType('clientUuid', 'string');
		$this->addType('body', 'string');
		$this->addType('fileId', 'integer');
		$this->addType('uploadId', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
	}
}
