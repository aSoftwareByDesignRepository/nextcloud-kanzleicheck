# Copied sources (provenance registry)

Code copied from sibling Check apps per the reuse-first rule. Each file
carries a `Copied from <app>@<rev>` header; this registry pins the sources
for drift checks. Adjustments beyond namespace/identity are listed.

| File | Source | Adjustments |
|------|--------|-------------|
| lib/Service/TokenService.php | bookingcheck@31c2669e70 lib/Service/TokenService.php | namespace; +intakeToken() HMAC helper |
| lib/Service/OtpService.php | bookingcheck@31c2669e70 lib/Service/OtpService.php | purpose binding (AF-08), verify() returns row id |
| lib/Service/PortalSessionService.php | bookingcheck@31c2669e70 lib/Service/PortalSessionService.php | sessions bind identity_id; sliding+abs expiry |
| lib/Middleware/RateLimitMiddleware.php | bookingcheck@31c2669e70 lib/Middleware/RateLimitMiddleware.php | KanzleiCheck route table |
| lib/Service/UploadService.php | ticketcheck@eb42a0f AttachmentUploadService (semantics) | rewritten as KanzleiCheck upload pipeline |
| lib/Service/SafeFilenameService.php | ticketcheck@eb42a0f lib/Service/SafeFilenameService.php | static variant; backslash→'/' normalize before basename (bug fix) |
| lib/Service/CsvFormulaGuard.php | ticketcheck@eb42a0f lib/Service/Export/CsvFormulaGuard.php | namespace |
| lib/Config/VendorPublicKey.php | ticketcheck@eb42a0f lib/Config/VendorPublicKey.php | KZC env names |
| lib/License/Kzc2Codec.php | ticketcheck@eb42a0f lib/License/Tkc2Codec.php | KZC2 payload (portalClients, bundle) |
| lib/Support/NavGate.php | _shared/nav-gate (synced via sync_nav_gate.py) | none — generated |

Do NOT edit copied files to match sibling drift — fixes go into the
shared pack or upstream app first, then re-copy/re-sync.
