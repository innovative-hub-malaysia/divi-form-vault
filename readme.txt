=== Divi Form Vault ===
Contributors: innovativehub
Tags: divi, contact form, leads, attribution, ga4
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Capture every Divi Contact Form submission as an attributed lead, with a backend analytics view and a GA4 generate_lead event.

== Description ==

Divi's Contact Form module emails submissions but stores nothing. Divi Form
Vault fills that gap and goes further: it captures every submission into a
dedicated database table as an attributed lead (UTM source / medium / campaign,
landing page, referrer, submit page, and device), gives sales a backend to
read and export the leads, shows a lightweight analytics view of which pages
and campaigns produce leads, and forwards each lead to GA4 as a generate_lead
event.

Ground rules:

* Does not build or modify the form. The Divi Contact Form module keeps owning
  rendering, validation, and the notification email. This plugin only records
  what was submitted.
* Builder-agnostic core, Divi adapter first. The capture core is generic; each
  form source is a pluggable adapter, so other builders can be added later
  without a core rewrite.
* Reusable - no hardcoded client names, fields, or copy; everything is
  configured in Settings.
* Manual data control (PDPA). It stores IP and attribution, so it provides
  export and delete tools, but it never auto-deletes. The site owner decides
  when to purge.

Works on any Divi site. If the site previously used the "Divi Contact Form DB"
plugin, existing submissions can be imported in one click on first activation.

== Changelog ==

= 1.1.1 =
* Fix: every date and time in the admin now follows the WordPress Timezone
  setting. The Submitted column, the lead detail, the Analytics trend and
  its 30-day / 7-day windows, and the date-range filters (list, CSV export,
  privacy delete) were all shown and compared in GMT, so a Kuala Lumpur site
  ran eight hours behind and a lead sent after midnight counted on the day
  before. Storage is unchanged (still GMT). The CSV export keeps every
  existing column in place and appends a site-time `submitted_at_local`.

= 1.1.0 =
* Updates now come from Innovative Hub's GitHub repository through the normal
  WordPress update flow: the Plugins screen shows "update available" and the
  plugin auto-updates by default (define DFV_DISABLE_AUTO_UPDATE to opt out).
  Sites on 1.0.0 need one manual upload of this version first.
* Description no longer presents the plugin as a replacement for Divi Contact
  Form DB - it works on any Divi site; the legacy import stays optional.

= 1.0.0 =
* First stable release. Full release review passed: every admin handler
  nonce+capability guarded, all stored (visitor-controlled) output escaped,
  SQL confined to the store/installer/importer with prepared statements and
  whitelisted columns, CSV formula-injection neutralised, handler/nonce/option
  inventories reconciled, no client-specific references anywhere.
* Proven on the first production site: activation with table-creation
  self-heal, backend screens, settings, and the complete legacy migration
  from the premium "Divi Contact Form DB" (old plugin retired).

= 0.1.8 =
* Analytics layout on a single aligned grid: one shared container width, KPI
  row 5-up, source cards 3-up, breakdown cards 2-up - every row shares the
  same left/right edges (responsive collapse on narrow screens).

= 0.1.7 =
* Legacy import v3 for the premium "Divi Contact Form DB": the whole record
  lives inside ONE wrapper meta (sb_divi_cfd) with the field list and page
  info nested as JSON strings inside it - the importer now unwraps the outer
  layer (array / serialised / JSON / slashed) and decodes the nested data,
  with the email sibling meta as a last-resort fallback. Verified against
  all three storage variants in simulation.

= 0.1.6 =
* Legacy import maps the premium "Divi Contact Form DB" (divi_cf_db) EXACTLY:
  the JSON {label,value} field list (slashed-JSON now decoded), page from its
  extra meta, clean submitted time; noise metas (raw POST dump, field
  definitions, read flags) excluded. Re-running after deleting earlier ugly
  rows imports them clean.
* Import-result notice is a true one-shot (transient-backed) - refreshing the
  page no longer resurrects it.
* Analytics polish: proper empty state for the 30-day flow (no more stub
  bars), baseline + date axis, zero-day bars muted, linked KPI numbers read
  as links, By-form no longer links labels the list cannot filter by.
* Submission detail shows humanised field labels (email-address -> Email
  Address).

= 0.1.5 =
* FIX the silent root cause found on the first site: the submissions table
  was never created (strict-mode zero-date default + a composite VARCHAR(191)
  index over the 767-byte limit killed the CREATE, dbDelta swallowed it, and
  every capture/import insert then failed). Schema is now shared-host-proof
  (no zero-date default, single-column indexes), creation is VERIFIED after
  dbDelta with a direct-CREATE fallback that captures the real DB error, the
  schema version is only stamped on success (so it retries until healthy),
  and a missing table shows a red admin notice with the DB error.
* DB schema 1.0.1 - existing installs self-heal on first load after update.
* Uniform section dividers on the Settings tab too.

= 0.1.4 =
* Data & Privacy: uniform section dividers; delete tools reduced to the date
  range (single rows and per-email cleanups moved to the Submissions list,
  which now has a per-row Delete action).
* Import results now report a reason breakdown (imported / already imported /
  unmappable / insert-failed) and capture the database error on failures.

= 0.1.3 =
* Legacy import: lossless post_content fallback, on-screen skip diagnostics,
  explicit post-status list.

= 0.1.2 =
* Backend restructure: six thin settings tabs + a standalone Privacy page
  collapsed into two real tabs - Settings (attribution, spam, GA4) and
  Data & Privacy (storage, export, delete tools, legacy import, uninstall).
* Analytics redesigned around the owner's questions: KPI row with deltas and
  action links, a "Needs attention" list of unread leads older than 3 days,
  linked top pages / sources / campaigns with share-of-total, lead flow.
* Legacy import now detects the premium "Divi Contact Form DB" (CPT
  divi_cf_db) via an adaptive mapper that harvests fields, page, form, and
  date from any meta shape without losing data.

= 0.1.1 =
* Legacy import now maps the real "Contact Form DB Divi" storage exactly
  (CPT lwp_form_submission + its meta), confirmed against the plugin source;
  its slug added to the auto-deactivation matcher.
* Divi submit-hook signature confirmed against the same reference (Divi 4/5).
* Onboarding no longer self-dismisses when detection finds nothing - the
  import offer survives until it runs or is explicitly dismissed.
* Plugin header description shortened under 200 characters (house rule).

= 0.1.0 =
* Initial build, all stages: scaffold + submissions table (Stage 0), capture
  core + Divi adapter (1), one-click legacy import + onboarding (2),
  attribution capture (3), spam flagging (4), backend submissions view (5),
  analytics dashboard (6), GA4 generate_lead push (7), privacy tools + docs (8).
* Not yet verified on a live WP + Divi runtime - see QA-CHECKLIST.md, starting
  with the two TO-VERIFY gates (Divi submit hook, old-plugin storage).
