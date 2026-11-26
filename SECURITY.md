# Security Policy

KanzleiCheck processes **confidential client data** — for the reference
deployment class this includes data under professional-secrecy obligations
(§62 StBerG / §35 BORO context: tax advisers, auditors, lawyers). We treat
security reports with the highest priority.

## Reporting a vulnerability

**Do not open a public issue for security vulnerabilities.**

Please report vulnerabilities privately by email to:

- **info@software-by-design.de** — subject: `KanzleiCheck: Security report`

Include:

- Affected version(s) and Nextcloud version
- Steps to reproduce / proof of concept
- Impact assessment (which client data or privilege is at risk)

You will receive an acknowledgement within a few days. We aim to release a
fix or a documented mitigation promptly, and we credit reporters in the
changelog unless you prefer to stay anonymous.

## Scope notes

- The staff SPA API, the client portal API (`/portal/api/*`), the
  OTP/magic-link auth flow, device-token pairing, third-party upload links
  (`ulink`), and the companion endpoints are in scope.
- The OTP login path is rate-limited (per-IP + per-email) by design;
  uniform responses for unknown addresses are intentional, not an oracle.
- Content-free notification e-mails are intentional — message bodies never
  leave the server via mail.
- NC-user binding (`kzc_sync`) intentionally shares an org's Files folder
  with bound client accounts; upload integrity is re-verified at staff
  review (`sha256` re-hash). Tamper-detection behavior is intentional.

## Hardening references

- Uniform 404 for out-of-scope IDs (no existence oracles)
- CSRF on all cookie-authed mutations; `X-KanzleiCheck-Device` header +
  hashed device tokens on companion API
- Single-use, purpose-bound OTPs; atomic claim/consume
- Optimistic-locking (revision CAS) on task/request mutations
- Append-only audit trail for security-relevant actions
- Signed KZC2 licenses (Ed25519); seat enforcement fails closed
- Config transfer containers are passphrase-encrypted + integrity-verified;
  apply runs transactional with referential-integrity check and rollback
