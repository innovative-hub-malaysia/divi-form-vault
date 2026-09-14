# Divi Form Vault - QA checklist (run on a real WP + Divi site)

Status 2026-07-28: deployed to the first live site - activation, table
self-heal, backend screens, settings, and the full legacy migration are
verified working there (sections 0 and 3). The remaining unchecked items
(live capture, attribution, spam, GA4) are the next site session's one-pass
matrix. On a new client site: staging / behind a fresh backup - never live.

## 0. Launch gates - RESOLVED on the first site (2026-07-28)

- [x] **Divi submit hook**: `et_pb_contact_form_submit` (3 args,
      `field_id => {label, value}`) confirmed against a reference
      implementation (Divi 4 + 5). Final confirmation = the first live
      submission below.
- [x] **Old plugin storage**: premium "Divi Contact Form DB" (`divi_cf_db`,
      `sb_divi_cfd` wrapper meta) mapped exactly and VERIFIED end-to-end -
      3 submissions migrated clean, old plugin retired. wp.org variant
      (`lwp_form_submission`) mapped from source. Unknown variants: adaptive
      mapper + on-screen skip diagnostics + `dfv_legacy_source` pin filter.
- [ ] **Binary IP round-trip**: submit once, check the detail view shows a valid
      IP (VARBINARY via `$wpdb->insert` - likely fine, confirm on the first
      live submission).

## 1. Activation / scaffold

- [ ] Activate on a Divi site: no notices/fatals; `{prefix}dfv_submissions` table created;
      `dfv_settings` / `dfv_version` / `dfv_db_version` options exist.
- [ ] Activate on a NON-Divi site: warning notice shows, admin works, no fatal, no capture.
- [ ] Deactivate: data + table survive. Uninstall with purge OFF: data survives.
      Uninstall with purge ON: table + options gone.

## 2. Capture + resilience

- [ ] Submit each Divi contact form on the site: exactly ONE row per submission,
      correct form id/name + page id/url/title, all fields readable.
- [ ] Two forms on one page: both capture with distinct form ids.
- [ ] Break the site's email (e.g. bad SMTP): submission still lands in the vault.
- [ ] Failed Divi validation (required field empty / wrong captcha): NO row stored.

## 3. Import (on a site with old-plugin data) - VERIFIED 2026-07-28

- [x] Detection + count correct (3 divi_cf_db submissions found).
- [x] Import: rows landed with clean fields + page + timestamp, `source = legacy`, status Read, no attribution.
- [x] Re-run import: 0 new rows (idempotent). Old plugin's data untouched.
- [x] Old plugin retired (removed by the site owner after verification).

## 4. Attribution

- [ ] Visit `/?utm_source=google&utm_medium=cpc&utm_campaign=qa` -> browse 2 pages -> submit:
      row carries those UTMs + landing page + referrer.
- [ ] Second visit with different UTMs then submit: last-touch shown, first touch
      preserved in detail (`_first_touch`, model = both).
- [ ] Direct visit (no UTMs): row has landing page + referrer, empty UTMs, still captures.
- [ ] Device column: desktop vs phone submission classify correctly.

## 5. Spam

- [ ] Fill the hidden `dfv_hp_website` field (dev tools) and submit: row flagged spam.
- [ ] Paste 3+ URLs in the message: flagged spam.
- [ ] Normal submission: NOT flagged. Mark spam / genuine toggles work (row + bulk).
- [ ] Spam rows excluded from analytics lead figures; visible under the spam filter.

## 6. Backend list + CSV

- [ ] Filters (form / source / spam / date range) + search each narrow correctly; combined filters AND.
- [ ] Detail view shows all fields + full attribution; viewing a New row marks it Read.
- [ ] Bulk mark spam / genuine / delete work. Menu badge = count of New genuine rows.
- [ ] CSV export respects the active filter; opens clean in Excel/Sheets (UTF-8).

## 7. Analytics

- [ ] Tiles + cards reconcile with the list-table counts for the same filters.
- [ ] 30-day trend bars match per-day counts; genuine vs spam split correct.

## 8. GA4

- [ ] With GTM present: submit -> `generate_lead` visible in GA4 DebugView with
      page/form/utm/device params; `lead_source = divi_form_vault`.
- [ ] With a Measurement ID configured (no GTM): gtag loads, event fires.
- [ ] Reload the thank-you page: NO duplicate event. Spam submission: NO event.
- [ ] Param names match the Request-a-Quote plugin (unified IH schema).

## 9. Data & Privacy tools (Settings > Data & Privacy)

- [ ] Export ALL streams a complete CSV.
- [ ] Delete by id / date range / email each delete exactly the matching rows, after the confirm.
- [ ] Legacy import row appears there while old data is detectable.
- [ ] No cron/scheduled deletion exists anywhere (`wp cron event list | grep dfv` is empty).

## 10. Reusability / polish

- [ ] Both Settings tabs save + persist; defaults sane on a fresh site.
- [ ] Admin screens usable at 375px width. Site front-end unchanged visually.
- [ ] With a page cache (WP Rocket etc): attribution cookie still captured
      (JS runs on cached pages) - and purge the cache after plugin updates.
- [ ] Table upgrade path: bump `DB_VERSION` on a schema change and confirm dbDelta applies it.
