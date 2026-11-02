<?php

declare(strict_types=1);

namespace OCA\PortalCheck\Service;

use OCA\PortalCheck\Db\FormAnswerMapper;
use OCA\PortalCheck\Db\FormTemplate;
use OCA\PortalCheck\Db\FormTemplateMapper;
use OCA\PortalCheck\Db\PortalForm;
use OCA\PortalCheck\Db\PortalFormMapper;
use OCA\PortalCheck\Exception\ApiException;
use OCA\PortalCheck\Exception\ConflictException;
use OCA\PortalCheck\Exception\NotFoundException;
use OCA\PortalCheck\Exception\ValidationException;
use OCP\IDBConnection;

/** Questionnaires (US-P28): field schema validation, autosave CAS, submit lock, CSV export. */
class FormService
{
	public const MAX_FIELDS = 100;
	public const TYPES = ['short_text','long_text','number','date','yes_no','single_choice','multi_choice','upload'];

	public function __construct(
		private readonly FormTemplateMapper $tpls,
		private readonly PortalFormMapper $forms,
		private readonly FormAnswerMapper $answers,
		private readonly IDBConnection $db,
	) {
	}

	/** Validate a field-schema JSON — returns normalized fields or throws. */
	public static function validateFields(array $fields): array
	{
		if (count($fields) < 1 || count($fields) > self::MAX_FIELDS) {
			throw new ValidationException('VALIDATION_FAILED', ['fields' => 'count']);
		}
		$out = [];
		$keys = [];
		foreach ($fields as $i => $f) {
			$key = (string)($f['key'] ?? '');
			if (!preg_match('/^[a-z0-9_]{1,64}$/', $key) || isset($keys[$key])) {
				throw new ValidationException('VALIDATION_FAILED', ['fields' => 'key:' . $i]);
			}
			$keys[$key] = true;
			$type = (string)($f['type'] ?? '');
			if (!in_array($type, self::TYPES, true)) {
				throw new ValidationException('VALIDATION_FAILED', ['fields' => 'type:' . $key]);
			}
			$label = trim((string)($f['label'] ?? ''));
			if ($label === '' || mb_strlen($label) > 190) {
				throw new ValidationException('VALIDATION_FAILED', ['fields' => 'label:' . $key]);
			}
			$norm = [
				'key' => $key, 'type' => $type, 'label' => $label,
				'required' => !empty($f['required']),
				'options' => in_array($type, ['single_choice','multi_choice'], true)
					? array_slice(array_values(array_filter(array_map('strval', (array)($f['options'] ?? [])))), 0, 30) : [],
				'showIf' => is_array($f['showIf'] ?? null) && isset($f['showIf']['field'], $f['showIf']['equals'])
					? ['field' => (string)$f['showIf']['field'], 'equals' => (string)$f['showIf']['equals']] : null,
				'min' => isset($f['min']) ? (float)$f['min'] : null,
				'max' => isset($f['max']) ? (float)$f['max'] : null,
			];
			if (in_array($type, ['single_choice','multi_choice'], true) && $norm['options'] === []) {
				throw new ValidationException('VALIDATION_FAILED', ['fields' => 'options:' . $key]);
			}
			$out[] = $norm;
		}
		return $out;
	}

	public function createTemplate(string $actor, string $name, array $fields): FormTemplate
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 190) {
			throw new ValidationException('VALIDATION_FAILED', ['name' => 'required']);
		}
		$norm = self::validateFields($fields);
		$t = new FormTemplate();
		$t->setName($name);
		$t->setFieldsJson(json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
		$t->setCreatedBy($actor);
		$t->setCreatedAt(new \DateTimeImmutable());
		return $this->tpls->insert($t);
	}

	public function instantiate(int $orgId, int $tplId, ?int $reqItemId): PortalForm
	{
		if ($this->tpls->find($tplId) === null) {
			throw new NotFoundException();
		}
		$f = new PortalForm();
		$f->setOrgId($orgId);
		$f->setTplId($tplId);
		$f->setReqItemId($reqItemId);
		$f->setState('open');
		$f->setCreatedAt(new \DateTimeImmutable());
		return $this->forms->insert($f);
	}

	public function mustGetInOrg(int $formId, int $orgId): PortalForm
	{
		$f = $this->forms->find($formId);
		if ($f === null || (int)$f->getOrgId() !== $orgId) {
			throw new NotFoundException();
		}
		return $f;
	}

	/** Validate one field value — returns normalized string or throws ValidationException. */
	public static function validateValue(array $field, string $value): string
	{
		$value = trim($value);
		switch ($field['type']) {
			case 'short_text': if (mb_strlen($value) > 500) throw new ValidationException('VALIDATION_FAILED', [$field['key'] => 'too_long']); break;
			case 'long_text': if (mb_strlen($value) > 10000) throw new ValidationException('VALIDATION_FAILED', [$field['key'] => 'too_long']); break;
			case 'number':
				if ($value !== '' && !is_numeric($value)) throw new ValidationException('VALIDATION_FAILED', [$field['key'] => 'number']);
				if ($value !== '' && $field['min'] !== null && (float)$value < $field['min']) throw new ValidationException('VALIDATION_FAILED', [$field['key'] => 'min']);
				if ($value !== '' && $field['max'] !== null && (float)$value > $field['max']) throw new ValidationException('VALIDATION_FAILED', [$field['key'] => 'max']);
				break;
			case 'date': if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) throw new ValidationException('VALIDATION_FAILED', [$field['key'] => 'date']); break;
			case 'yes_no': if (!in_array($value, ['yes','no',''], true)) throw new ValidationException('VALIDATION_FAILED', [$field['key'] => 'choice']); break;
			case 'single_choice': if ($value !== '' && !in_array($value, $field['options'], true)) throw new ValidationException('VALIDATION_FAILED', [$field['key'] => 'choice']); break;
			case 'multi_choice':
				if ($value !== '') {
					foreach (explode(',', $value) as $v) {
						if (!in_array(trim($v), $field['options'], true)) throw new ValidationException('VALIDATION_FAILED', [$field['key'] => 'choice']);
					}
				}
				break;
		}
		return $value;
	}

	/** Autosave one field — per-field revision CAS (409 CONFLICT on stale). */
	public function saveAnswer(string $actor, int $formId, int $orgId, string $fieldKey, string $value, int $expectedRevision): array
	{
		$form = $this->mustGetInOrg($formId, $orgId);
		if ($form->getState() === 'submitted') {
			throw new ApiException(409, 'FORM_LOCKED', 'form submitted');
		}
		$tpl = $this->tpls->find((int)$form->getTplId());
		$fields = json_decode((string)$tpl?->getFieldsJson(), true) ?: [];
		$field = null;
		foreach ($fields as $f) {
			if ($f['key'] === $fieldKey) {
				$field = $f;
				break;
			}
		}
		if ($field === null) {
			throw new NotFoundException();
		}
		$value = self::validateValue($field, $value);
		$newRev = $this->answers->casWrite($formId, $fieldKey, $value, $expectedRevision, $actor);
		if ($newRev === -1) {
			$current = $this->answers->find($formId, $fieldKey);
			throw new ConflictException('CONFLICT', ['value' => $current['value'] ?? null, 'author' => $current['updated_by'] ?? null, 'revision' => $current['revision'] ?? 0]);
		}
		return ['revision' => $newRev];
	}

	/** Submit: required fields validated; then locked. */
	public function submit(string $actor, int $formId, int $orgId): void
	{
		$form = $this->mustGetInOrg($formId, $orgId);
		if ($form->getState() === 'submitted') {
			throw new ApiException(409, 'FORM_LOCKED', 'form submitted');
		}
		$tpl = $this->tpls->find((int)$form->getTplId());
		$fields = json_decode((string)$tpl?->getFieldsJson(), true) ?: [];
		$answers = $this->answers->allForForm($formId);
		$errors = [];
		foreach ($fields as $f) {
			if (empty($f['required'])) {
				continue;
			}
			$v = $answers[$f['key']]['value'] ?? null;
			if ($v === null || trim((string)$v) === '') {
				$errors[$f['key']] = 'required';
			}
		}
		if ($errors !== []) {
			throw new ValidationException('VALIDATION_FAILED', $errors);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('ptl_form')
			->set('state', $qb->createNamedParameter('submitted'))
			->set('submitted_by', $qb->createNamedParameter($actor))
			->set('submitted_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($formId)))
			->andWhere($qb->expr()->neq('state', $qb->createNamedParameter('submitted')));
		$qb->executeStatement();
	}

	public function reopen(int $formId, string $actor): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('ptl_form')->set('state', $qb->createNamedParameter('open'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($formId)));
		$qb->executeStatement();
	}
}
