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

## Synced shared packs (check-shell family chrome)

Generated files — edit the canonical source under `_shared/`, then re-run
the syncer. `--check` reports drift. Never edit these in-app.

| Pack | Syncer | Targets in this app |
|------|--------|---------------------|
| check-shell | `_shared/check-shell/sync_check_shell.py --app kanzleicheck --prefix kzc --global KZC --ns KanzleiCheck --title KanzleiCheck` | `css/common/*`, `js/app.js`, `js/common/{mobile-nav,toasts}.js`, `templates/common/*`, `lib/Service/IconCatalog.php`, `lib/Support/AssetRegistrar.php` |
| field-errors | `_shared/field-errors/sync_field_errors.py` | `js/common/field-errors.js`, `css/common/field-errors.css` |
| focus-preservation | `_shared/focus-preservation/sync_focus_preservation.py` | `js/common/focus-preservation.js` |
| view-components | `_shared/view-components/sync_view_components.py` | `js/common/view-components.js`, `css/common/view-components.css` |
| app-feedback | `_shared/app-feedback/sync_app_feedback.py` | `js/common/app-feedback.js`, `templates/parts/feedback-nav-footer.php`, `lib/Support/AppFeedbackLinks.php`, nav-footer block in `css/app.css` |
| support-us | `_shared/support-us/sync_support_us.py` | `templates/parts/support-us-section.php`, `lib/Support/SupportUsLinks.php`, support-us block in `css/app.css`, l10n key merges |
| config-transfer | `_shared/config-transfer/` (existing) | `templates/parts/settings/transfer.php`, `css/common/transfer.css`, `js/transfer*.js`, `lib/ConfigTransfer/*` |

Do NOT edit copied files to match sibling drift — fixes go into the
shared pack or upstream app first, then re-copy/re-sync.
