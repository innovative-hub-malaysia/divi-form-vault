# Divi Form Vault

An IH-owned, reusable WordPress plugin that captures every **Divi Contact Form**
submission into the WP backend as an **attributed lead** (UTM / source / landing
page / submit page / device), gives sales a backend list + analytics view, and
pushes a GA4 `generate_lead` event. Replaces the abandoned "Divi Contact Form DB"
plugin, with a one-click import of its history.

**We do not build the form.** The Divi Contact Form module keeps owning
rendering, validation, and the notification email. This plugin only records what
was submitted.

## How it works

| Piece | What it does |
|---|---|
| Capture core | Builder-agnostic pipeline: normalise -> attribute -> spam-check -> store -> announce |
| Divi adapter | Hooks Divi's contact-form submit action, normalises the payload (the ONLY Divi-aware file) |
| Attribution | Front-end cookie keeps first + last touch UTMs, landing page, referrer; attached at capture |
| Spam | Honeypot (JS-injected, Divi markup untouched) + heuristics; flags, never blocks |
| Backend | Submissions list (filters, detail, bulk, CSV) + analytics + data/privacy tools |
| GA4 | `generate_lead` with the shared IH schema (`lead_source`, `method`, page/form/utm/device) |
| Import | Detects the old "Divi Contact Form DB" data, one-click idempotent import, old data untouched |

## Install / first run

1. Upload the `divi-form-vault` folder (or the zip from the latest GitHub
   Release) to `wp-content/plugins/`, activate.
2. Requires the Divi theme or Divi Builder plugin; without it the plugin loads
   safely (admin + data available) and shows a notice - live capture waits.
3. On a site running the old "Divi Contact Form DB": an onboarding notice offers
   the one-click import; after import it deactivates the old plugin (or tells
   you to). Old data is never modified or deleted.
4. Configure under **Form Vault > Settings** - two tabs: **Settings**
   (attribution, spam, GA4) and **Data & Privacy** (what is stored, export,
   manual delete tools, legacy import, uninstall behaviour). Defaults are
   sane; a new site works out of the box.

## Updates

Installed sites update themselves from this repository's GitHub Releases
(bundled `lib/plugin-update-checker`): the Plugins screen shows the update and
auto-updates are on by default - define `DFV_DISABLE_AUTO_UPDATE` as `true` in
`wp-config.php` to opt a site out. Sites on 1.0.0 need one manual upload of a
newer version first.

To ship a version: bump `Version:` + `DFV_VERSION` + `Stable tag` (all three
must match), add the changelog entry to `readme.txt`, commit, then
`bin/build-release.sh --publish` - it builds the zip with the plugin slug as
the top folder, tags `vX.Y.Z` and creates the Release with the zip attached.
Every site picks it up within ~12 hours.

## Per-client reuse

Nothing client-specific lives in code - deploy the same plugin to any Divi site.
GA4: if the site runs GTM, events ride the existing dataLayer; else set a
Measurement ID under Settings. PDPA stance: manual export/delete tools under
Settings > Data & Privacy, never automatic deletion.

## Verification status (first site: 2026-07-28)

The two launch gates are RESOLVED:

1. **The Divi submit hook** - `et_pb_contact_form_submit` (3 args,
   `field_id => {label, value}`) confirmed against a reference implementation
   declaring Divi 4 + Divi 5 compatibility. The adapter stays shape-tolerant.
2. **The old plugin's storage** - both known variants mapped and the premium
   one verified END-TO-END on a live site (3 submissions migrated clean, old
   plugin retired): wp.org "Contact Form DB Divi" (`lwp_form_submission`) and
   the premium "Divi Contact Form DB" (`divi_cf_db`, single `sb_divi_cfd`
   wrapper meta with nested slashed-JSON). Unknown variants fall back to an
   adaptive mapper + on-screen skip diagnostics; a custom source can be
   pinned via the `dfv_legacy_source` filter.

Also proven on the first site: the table-creation self-heal (see
QA-CHECKLIST.md for the full deploy matrix and the remaining per-site checks:
first live submission, GA4 DebugView, spam paths).

## Hooks for developers

| Hook | Type | Purpose |
|---|---|---|
| `dfv_capture_submission` | filter | Mutate the normalised submission before storage |
| `dfv_capture_attribution` | filter | Supply attribution columns (+ `_extra_fields`) |
| `dfv_capture_is_spam` | filter | Spam verdict for a row |
| `dfv_submission_captured` | action | After insert: `($id, $row)` |
| `dfv_ga4_params` | filter | Adjust the `generate_lead` params |
| `dfv_legacy_source` | filter | Pin the legacy import source descriptor |
| `dfv_client_ip` | filter | Correct the client IP behind a proxy/CDN |
| `dfv_spam_heuristics_verdict` | filter | Add site-specific spam heuristics |

## Adding another builder (adapter guide)

Implement `DFV_Source_Interface` (see `includes/capture/interface-dfv-source.php`
for the contract + the generic submission shape), hook your builder's submit
event, normalise, call `DFV_Capture::capture()`. Register it in
`DFV_Plugin::boot()` behind your builder's presence check. The core (attribution,
spam, storage, backend, GA4) needs no change - if it does, fix the core.

## House rules

English only, no em-dashes, GPL-2.0-or-later, prefix `dfv_`, text domain
`divi-form-vault`. Never `git add -A`. Build/test on staging or behind a fresh
backup - never on a live client site.
