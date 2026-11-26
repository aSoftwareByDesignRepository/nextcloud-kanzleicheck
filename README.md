# KanzleiCheck

[![Nextcloud](https://img.shields.io/badge/Nextcloud-32–35-0082c9?logo=nextcloud&logoColor=white)](https://nextcloud.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2–8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL--3.0-blue.svg)](LICENSE)

**[English](#english)** · **[Deutsch](#deutsch)**

A client portal that lives entirely inside your Nextcloud — passwordless
client access, chat, documents, requests and approvals.

---

## English

**Your clients log in with an e-mail code — not a Nextcloud account.**

KanzleiCheck gives your external clients (mandants, customers, tenants) a
clean portal without burning user slots or handing out passwords: e-mail OTP
or magic link on the web, device pairing with biometric unlock in the
companion app.

### Why teams install it

- Passwordless client login (6-digit code or magic link); per-client
  conversations, documents, and tasks — strictly org-scoped
- Document requests: checklists, deadlines, automatic reminders, recurring
  templates per industry preset
- Approvals: code-confirmed, hash-bound approval records with print proof
  (not an eIDAS signature)
- Documents: per-client Eingang/Dokumente folders in your Files, chunked
  uploads, duplicate detection, third-party upload links
- Questionnaires, staff task board, per-org staff responsibility
- E-mail intake per client org (optional), contact person card + theming
- Companion app (client + staff roles) — `mobile/kanzleicheck`
- Access door (Open/Restricted + app admin), audit log, GDPR export/erase

### Clear limits

- All data stays on your server — no third-party services in the data path.
- Approvals are evidence records, **not** qualified e-signatures (eIDAS).
- Notifications: Nextcloud bell, content-free e-mails, companion background
  refresh. No native push.
- Client logins need working outgoing mail in your Nextcloud.

### Requirements

- Nextcloud 32–35 · PHP 8.2–8.5 · MySQL/MariaDB, PostgreSQL, or SQLite
- Working outgoing mail configuration (client login codes)

### Install

**App Store (recommended):** Apps → search **KanzleiCheck** → download and enable.

**From Git:**

```bash
git clone https://github.com/aSoftwareByDesignRepository/nextcloud-kanzleicheck.git /path/to/nextcloud/apps/kanzleicheck
cd /path/to/nextcloud
php occ app:enable kanzleicheck
```

### Documentation

- Changelog: [`CHANGELOG.md`](CHANGELOG.md)
- Copied-source attribution: [`COPIED-SOURCES.md`](COPIED-SOURCES.md)

### Security & support

Report security issues privately per [`SECURITY.md`](SECURITY.md).  
**Software by Design GbR** · [nextcloud.software-by-design.de](https://nextcloud.software-by-design.de/) · [info@software-by-design.de](mailto:info@software-by-design.de) · [Support packages](https://nextcloud.software-by-design.de/en/support.html#packages)

### License

[AGPL-3.0-or-later](LICENSE).

---

## Deutsch

**Ihre Mandanten melden sich mit einem E-Mail-Code an — kein Nextcloud-Konto.**

KanzleiCheck gibt externen Kunden (Mandanten, Auftraggebern, Mietern) ein
sauberes Portal ohne Benutzer-Slots und ohne Passwörter: E-Mail-OTP oder
Magic-Link im Web, Gerätekopplung mit biometrischer Entsperrung in der
Companion-App.

### Warum Teams es installieren

- Passwortloser Kundenlogin (6-stelliger Code oder Magic-Link);
  Konversationen, Dokumente und Aufgaben strikt pro Organisation getrennt
- Dokumenten-Anfragen: Checklisten, Fristen, automatische Erinnerungen,
  wiederkehrende Vorlagen je Branchen-Preset
- Freigaben: codebestätigt, hash-gebunden, mit Drucknachweis
  (keine eIDAS-Signatur)
- Dokumente: Eingang/Dokumente-Ordner pro Kunde in Ihren Dateien,
  Chunk-Uploads, Duplikaterkennung, Upload-Links für Dritte
- Fragebögen, Aufgabenboard, Verantwortlichkeit pro Kunde
- Optionale E-Mail-Intake pro Kunde, Ansprechpartner-Karte + Branding
- Companion-App (Kunden- und Mitarbeiter-Rolle) — `mobile/kanzleicheck`
- Zugangssteuerung (Offen/Eingeschränkt + App-Admin), Audit-Log,
  DSGVO-Export/-Löschung

### Klare Grenzen

- Alle Daten bleiben auf Ihrem Server — keine Drittdienste im Datenpfad.
- Freigaben sind Nachweisdokumente, **keine** qualifizierten e-Signaturen.
- Benachrichtigungen: Nextcloud-Benachrichtigungen, inhaltfreie E-Mails,
  Hintergrund-Aktualisierung der App. Kein nativer Push.
- Kundenlogins brauchen eine funktionierende Mail-Konfiguration.

### Voraussetzungen

- Nextcloud 32–35 · PHP 8.2–8.5 · MySQL/MariaDB, PostgreSQL oder SQLite
- Funktionierende Ausgangsmail-Konfiguration (Login-Codes)

### Installation

**App Store (empfohlen):** Apps → **KanzleiCheck** suchen → herunterladen und aktivieren.

**Aus Git:** siehe englischen Abschnitt.

### Dokumentation

- Changelog: [`CHANGELOG.md`](CHANGELOG.md)
- Kopier-Quellen: [`COPIED-SOURCES.md`](COPIED-SOURCES.md)

### Sicherheit & Support

Sicherheitsmeldungen privat gemäß [`SECURITY.md`](SECURITY.md).  
**Software by Design GbR** · [Website](https://nextcloud.software-by-design.de/) · [info@software-by-design.de](mailto:info@software-by-design.de) · [Support-Pakete](https://nextcloud.software-by-design.de/de/support.html#packages)

### Lizenz

[AGPL-3.0-or-later](LICENSE).
