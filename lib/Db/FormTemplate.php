<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ``kzc_form_tpl`` entity.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getFieldsJson()
 * @method void setFieldsJson(string $fieldsJson)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(\DateTimeImmutable $createdAt)
 */
class FormTemplate extends Entity
{
	protected $name;
	protected $fieldsJson;
	protected $createdBy;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('name', 'string');
		$this->addType('fieldsJson', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime_immutable');
	}
}
