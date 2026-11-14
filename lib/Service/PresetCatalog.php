<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\KanzleiCheck\Service;

/**
 * Industry presets (SPEC §2.7) — configuration only: labels, defaults,
 * reminder defaults and preset template sets. Switching presets never
 * changes stored data, only presentation + defaults for new objects.
 */
class PresetCatalog
{
	public const IDS = ['generic', 'tax_office', 'agency', 'property', 'it_service'];

	/** @return array{orgLabel:string,orgPerson:string,board:array{new:string,in_progress:string,waiting:string,done:string},urgent:bool,visibility:string} */
	public function get(string $preset, string $lang = 'de'): array
	{
		$map = [
			'generic' => [
				'de' => ['orgLabel' => 'Kunde', 'orgPerson' => 'Kunde', 'board' => ['new' => 'Neu', 'in_progress' => 'In Bearbeitung', 'waiting' => 'Wartet', 'done' => 'Erledigt']],
				'en' => ['orgLabel' => 'Client', 'orgPerson' => 'Client', 'board' => ['new' => 'New', 'in_progress' => 'In progress', 'waiting' => 'Waiting', 'done' => 'Done']],
				'urgent' => true, 'visibility' => 'status',
			],
			'tax_office' => [
				'de' => ['orgLabel' => 'Mandat', 'orgPerson' => 'Mandant', 'board' => ['new' => 'Eingang', 'in_progress' => 'In Bearbeitung', 'waiting' => 'In Abstimmung', 'done' => 'Erledigt']],
				'en' => ['orgLabel' => 'Engagement', 'orgPerson' => 'Client', 'board' => ['new' => 'Inbox', 'in_progress' => 'In progress', 'waiting' => 'Awaiting client', 'done' => 'Done']],
				'urgent' => false, 'visibility' => 'status',
			],
			'agency' => [
				'de' => ['orgLabel' => 'Kunde', 'orgPerson' => 'Kunde', 'board' => ['new' => 'Briefing', 'in_progress' => 'In Arbeit', 'waiting' => 'Abnahme', 'done' => 'Fertig']],
				'en' => ['orgLabel' => 'Client', 'orgPerson' => 'Client', 'board' => ['new' => 'Briefing', 'in_progress' => 'In progress', 'waiting' => 'Review', 'done' => 'Done']],
				'urgent' => true, 'visibility' => 'status',
			],
			'property' => [
				'de' => ['orgLabel' => 'Objekt', 'orgPerson' => 'Eigentümer/Mieter', 'board' => ['new' => 'Gemeldet', 'in_progress' => 'In Bearbeitung', 'waiting' => 'Wartet auf Rückmeldung', 'done' => 'Erledigt']],
				'en' => ['orgLabel' => 'Property', 'orgPerson' => 'Owner/Tenant', 'board' => ['new' => 'Reported', 'in_progress' => 'In progress', 'waiting' => 'Waiting for reply', 'done' => 'Done']],
				'urgent' => true, 'visibility' => 'status',
			],
			'it_service' => [
				'de' => ['orgLabel' => 'Kunde', 'orgPerson' => 'Kunde', 'board' => ['new' => 'Neu', 'in_progress' => 'In Bearbeitung', 'waiting' => 'Wartet auf Kunde', 'done' => 'Gelöst']],
				'en' => ['orgLabel' => 'Client', 'orgPerson' => 'Client', 'board' => ['new' => 'New', 'in_progress' => 'In progress', 'waiting' => 'Waiting for client', 'done' => 'Solved']],
				'urgent' => true, 'visibility' => 'status',
			],
		];
		$p = $map[$preset] ?? $map['generic'];
		$l = is_array($p[$lang] ?? null) ? $p[$lang] : $p['en'];
		return [
			'orgLabel' => $l['orgLabel'],
			'orgPerson' => $l['orgPerson'],
			'board' => $l['board'],
			'urgent' => $p['urgent'],
			'visibility' => $p['visibility'],
		];
	}

	/** Default reminder policy per preset (SPEC US-P22 AC-1). */
	public function defaultReminderPolicy(): string
	{
		return '-3,0,+3x5';
	}

	/**
	 * Preset template sets (US-P22 AC-5) — shipped as editable copies.
	 * @return list<array{name:string,items:list<array{label:string,hint:string,kind:string}>,offsetDays:int,recurrence:string,recurrenceDay:?int}>
	 */
	public function templateSet(string $preset): array
	{
		switch ($preset) {
			case 'tax_office':
				return [
					['name' => 'Buchhaltung monatlich', 'offsetDays' => 10, 'recurrence' => 'monthly', 'recurrenceDay' => 5, 'items' => [
						['label' => 'Kontoauszüge alle Konten', 'hint' => 'PDF-Export aus dem Online-Banking genügt', 'kind' => 'upload'],
						['label' => 'Belege des Monats', 'hint' => '', 'kind' => 'upload'],
						['label' => 'Keine weiteren Belege vorhanden', 'hint' => 'Bitte bestätigen, wenn alles vollständig ist', 'kind' => 'confirm'],
					]],
					['name' => 'Lohndaten monatlich', 'offsetDays' => 5, 'recurrence' => 'monthly', 'recurrenceDay' => 3, 'items' => [
						['label' => 'Veränderungen (Eintritte, Austritte, Stunden)', 'hint' => '', 'kind' => 'upload'],
						['label' => 'Keine Veränderungen', 'hint' => '', 'kind' => 'confirm'],
					]],
					['name' => 'Jahresabschluss', 'offsetDays' => 30, 'recurrence' => 'yearly', 'recurrenceDay' => 15, 'items' => [
						['label' => 'Inventurliste / Anlagenverzeichnis', 'hint' => '', 'kind' => 'upload'],
						['label' => 'Offene Forderungen und Verbindlichkeiten', 'hint' => '', 'kind' => 'upload'],
					]],
					['name' => 'Einkommensteuer privat', 'offsetDays' => 21, 'recurrence' => 'yearly', 'recurrenceDay' => 28, 'items' => [
						['label' => 'Belege Werbungskosten und Sonderausgaben', 'hint' => '', 'kind' => 'upload'],
						['label' => 'Fragebogen Änderungen', 'hint' => '', 'kind' => 'confirm'],
					]],
				];
			case 'agency':
				return [
					['name' => 'Briefing-Unterlagen', 'offsetDays' => 7, 'recurrence' => 'none', 'recurrenceDay' => null, 'items' => [
						['label' => 'Logo und Markenvorgaben', 'hint' => '', 'kind' => 'upload'],
						['label' => 'Inhalte / Texte', 'hint' => '', 'kind' => 'upload'],
					]],
					['name' => 'Abnahme', 'offsetDays' => 7, 'recurrence' => 'none', 'recurrenceDay' => null, 'items' => [
						['label' => 'Feedback zur Entwurfsrunde', 'hint' => '', 'kind' => 'confirm'],
					]],
				];
			case 'property':
				return [
					['name' => 'Jahresabrechnung Belege', 'offsetDays' => 30, 'recurrence' => 'yearly', 'recurrenceDay' => 31, 'items' => [
						['label' => 'Rechnungen und Belege', 'hint' => '', 'kind' => 'upload'],
						['label' => 'Zählerstände', 'hint' => '', 'kind' => 'upload'],
					]],
				];
			case 'it_service':
				return [
					['name' => 'Onboarding-Zugänge', 'offsetDays' => 5, 'recurrence' => 'none', 'recurrenceDay' => null, 'items' => [
						['label' => 'Zugangsdaten / VPN-Profile', 'hint' => '', 'kind' => 'upload'],
						['label' => 'Geräteliste', 'hint' => '', 'kind' => 'upload'],
					]],
					['name' => 'Abnahme', 'offsetDays' => 7, 'recurrence' => 'none', 'recurrenceDay' => null, 'items' => [
						['label' => 'Abnahme der Leistung', 'hint' => '', 'kind' => 'confirm'],
					]],
				];
		}
		return [];
	}
}
