# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

## 0.2.0 — 2026-11-26

First public release. Complete client portal inside Nextcloud for tax
offices, agencies, property managers and any team serving external clients.

### Added

- Passwordless client portal: e-mail OTP (6-digit, purpose-bound,
  single-use) + magic link; device tokens with biometric unlock in the
  companion app; no Nextcloud accounts or user slots for clients
- One chat conversation per client org — shared staff inbox, read markers,
  unread badges, poll-chain with stale-response guards
- Document requests: per-item state machine
  (open → delivered → accepted/rejected → delivered again), checklists,
  deadlines, recurring templates per industry preset, automatic reminders
- Approvals: code-confirmed, hash-bound approval records (file etag +
  sha256), void-on-change detection, print proof
- Documents: per-org Eingang/Dokumente folders, chunked upload protocol
  with atomic assembly + idle-cleanup job, duplicate-hash detection,
  third-party upload links (ulink) with byte/file caps and expiry
- Questionnaires: template builder, client autosave + conflict CAS, review,
  print/CSV
- Staff task board (kanban) with drag & drop, revision CAS, portal
  visibility toggle, per-org staff responsibility
- E-mail intake per client org: IMAP pipeline, sender policy, hold queue,
  attachment ingest — opt-in per org
- Client identities: membership admin UI, GDPR export/erase/offboard,
  CSV import (web + `occ`), NC-user binding phase 1 (attach existing NC
  accounts for desktop sync, fail-closed door interaction, upload integrity
  re-verify)
- Notifications: NC bell for staff, content-free client e-mails via mail
  outbox (idempotency-keyed, throttled, opt-out, bounded retry), companion
  background refresh
- Licensing: signed KZC2 (Ed25519) licenses, app-enabled org seats + named
  mobile staff seats — both enforced fail-closed
- Config transfer: passphrase-encrypted export/import containers, merge +
  confirmed replace modes, transactional apply with referential-integrity
  verification and rollback, receipt journal
- Access door: Open/Restricted + allowed groups/users + dedicated App
  Admin role; setup wizard with presets; settings catalog (one page per
  topic); diagnostics (mail test, cron check, plus-addressing probe)
- Integration surfaces: InvoiceCheck invoice panel, BookingCheck booking
  link (soft-absent when siblings unavailable)
- Companion app `mobile/kanzleicheck`: client role (pair, todo, chat,
  documents, scanner with blur warning, approvals, forms) + staff role
  (login flow v2, org inbox, upload queue with Seen/Filed/Publish,
  seat-gated)
- 15 locales (de, en + 13 community languages); WCAG 2.1 AA; responsive
  from 320 px
- Retention job, audit log, uninstall repair steps (clean drop)

### Security

- Uniform 404 for out-of-scope IDs (no existence oracles); deny-by-default
  route guards on both portal and staff APIs
- CSRF on cookie-authed mutations; `X-KanzleiCheck-Device` custom-header
  device auth (CSRF-immune by construction)
- Layered rate limiting: per-IP `AnonRateLimit`, per-email OTP cap,
  per-actor chat sends, brute-force table integration
- Strict parameter guards on all mutation endpoints — non-scalar JSON
  fields reject instead of coercing
- Production-readiness audit (six-lens pass, 2026-11-26): scalar-coercion
  hardening across controllers/services, transaction-scoped cap checks
  (FOR UPDATE), upload-ledger disk-truth accounting, compensating cleanup
  on partial write failures
