<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Db;

use OCP\IDBConnection;

/** `kzc_form_ans` — per-field autosave answers with revision CAS. */
class FormAnswerMapper
{
	public const TABLE = 'kzc_form_ans';

	public function __construct(private readonly IDBConnection $db)
	{
	}

	/** @return array{value:?string,revision:int,updated_by:string,updated_at:string}|null */
	public function find(int $formId, string $fieldKey): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)))
			->andWhere($qb->expr()->eq('field_key', $qb->createNamedParameter($fieldKey)));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : [
			'value' => $row['value'] === null ? null : (string)$row['value'],
			'revision' => (int)$row['revision'],
			'updated_by' => (string)$row['updated_by'],
			'updated_at' => (string)$row['updated_at'],
		];
	}

	/** @return array<string,array{value:?string,revision:int,updated_by:string,updated_at:string}> */
	public function allForForm(int $formId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)));
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() ?: [] as $row) {
			$out[(string)$row['field_key']] = [
				'value' => $row['value'],
				'revision' => (int)$row['revision'],
				'updated_by' => (string)$row['updated_by'],
				'updated_at' => (string)$row['updated_at'],
			];
		}
		return $out;
	}

	/**
	 * Revision-CAS upsert. Returns the new revision on success; -1 on a stale
	 * write (another actor bumped the revision first).
	 */
	public function casWrite(int $formId, string $fieldKey, ?string $value, int $expectedRevision, string $actor): int
	{
		$now = new \DateTime();
		$existing = $this->find($formId, $fieldKey);
		if ($existing === null) {
			if ($expectedRevision !== 0) {
				return -1;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::TABLE)->values([
				'form_id' => $qb->createNamedParameter($formId),
				'field_key' => $qb->createNamedParameter($fieldKey),
				'value' => $qb->createNamedParameter($value),
				'revision' => $qb->createNamedParameter(1),
				'updated_by' => $qb->createNamedParameter($actor),
				'updated_at' => $qb->createNamedParameter($now, 'datetime'),
			]);
			try {
				$qb->executeStatement();
				return 1;
			} catch (\Throwable) {
				return -1; // raced insert — caller reads current row
			}
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('value', $qb->createNamedParameter($value))
			->set('revision', $qb->createFunction('revision + 1'))
			->set('updated_by', $qb->createNamedParameter($actor))
			->set('updated_at', $qb->createNamedParameter($now, 'datetime'))
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId)))
			->andWhere($qb->expr()->eq('field_key', $qb->createNamedParameter($fieldKey)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expectedRevision)));
		return $qb->executeStatement() === 1 ? $expectedRevision + 1 : -1;
	}
}
