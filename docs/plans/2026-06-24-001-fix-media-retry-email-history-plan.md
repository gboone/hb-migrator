---
title: "fix: Media retry, email suppression, and migration history"
date: 2026-06-24
type: fix
status: ready
---

# fix: Media retry, email suppression, and migration history

## Summary

Three independent fixes to the live migration pipeline:

1. Individual media attachment failures are silently swallowed — `download_url()`, `wp_handle_sideload()`, and `wp_insert_attachment()` all use `continue`, the stage offset advances regardless, and the item is permanently lost. A targeted retry pass is needed.
2. New users created during import trigger WordPress new-user email notifications (including from plugins hooked to `user_register`), spamming migrated authors.
3. Completed migration results disappear from the source admin UI when the user navigates away or clicks Clear, leaving no audit trail.

---

## Problem Frame

**Media:** `MediaImporter::process()` fetches attachments in batches of 50 by offset. When individual items fail (network error, disk issue, DB error), the batch still completes, `stage_offset` advances by the full `count($media)`, and the failed items are gone. `PipelineController::handle_batch_failure()` handles exception-level (whole-batch) failures only. There is no item-level retry.

**Email:** `wp_insert_user()` fires the `user_register` action. WordPress core's `wp_send_new_user_notifications()` is hooked to `register_new_user` and `edit_user_created_user` — not fired by `wp_insert_user()` — so core itself is not the culprit. However, plugins commonly hook `user_register` to send welcome or notification emails, and this cannot be suppressed on a plugin-by-plugin basis.

**History:** `hbm_active_migration` is a single site option. It holds one migration at a time and is deleted (or replaced) whenever the user clicks Clear or starts a new migration. The destination's `hbm_migrations` table has the full record, but the source has no persistent summary.

---

## Requirements

| ID | Requirement |
|----|-------------|
| R1 | Attachments that fail `download_url()`, `wp_handle_sideload()`, or `wp_insert_attachment()` are retried up to `hbm_max_retries` times with exponential backoff. |
| R2 | Attachment IDs that still fail after all retries are appended to `error_message` on the site_job (visible in migration status UI). |
| R3 | No email is sent to any user created during `UserImporter::process()`, regardless of plugins hooked to `user_register`. |
| R4 | Completed and cleared migrations are persisted in a history on the source (last 10 entries). |
| R5 | Past migrations are visible on the source admin page below the config form. |
| R6 | History is saved both when the destination reports `complete` and when the user clicks Clear (capturing partial and failed migrations). |

---

## Key Technical Decisions

**KTD1: Targeted retry via `source_attachment_ids` parameter, not offset re-run.**
Retrying the whole batch (same offset) works only if the source returns items in stable order; it also repeats unnecessary IdMap checks for the majority of items that succeeded. Passing explicit source attachment IDs to both the AS action and the source API is more precise, requires only one small source API addition (`post__in`), and composes cleanly with the existing `attempt` counter.

**KTD2: `pre_wp_mail` filter for email suppression, not targeted hooks.**
Filtering `wp_new_user_notification_email` only addresses core's notification path. Plugins may call `wp_mail()` directly on `user_register`. `pre_wp_mail` (available since WP 5.7, within this plugin's 6.4 minimum) short-circuits `wp_mail()` before any mail is composed, catching all callers. The filter is added immediately before the user loop and removed immediately after, minimising blast radius.

**KTD3: History saved server-side at two trigger points.**
History saved client-side (from JS polling) is fragile — the browser tab may be closed before completion. Two server-side hooks cover all cases:
- `SiteIndex::proxy_migration_status()` — already detects `status === 'complete'`; add a save-and-deduplicate call there.
- `AdminPage::handle_clear_migration()` — fetch current destination status, save to history, then delete `hbm_active_migration`.

**KTD4: History stored as a JSON-encoded array in `hbm_migration_history` site option.**
No schema change required. Keep last 10 entries; newer entries replace older ones when the cap is reached. Each entry stores: `migration_id`, `dest_url`, `started_at`, `saved_at`, `status`, and a `sites` array (source_domain, dest_path, status, error_message per site).

**KTD5: `started_at` added to `hbm_active_migration`.**
`handle_start_migration()` already writes this option. Adding `started_at => time()` at that point makes it available when the history entry is constructed — no additional DB round-trips needed.

---

## Implementation Units

### U1. Support `ids` parameter in source media endpoint

**Goal**: Allow `MediaImporter` to fetch specific attachment IDs from the source rather than paginating by offset.

**Requirements**: R1

**Dependencies**: None

**Files**:
- `includes/source/class-media-reader.php` (modify)
- `tests/test-site-index.php` (extend — or create `tests/test-media-reader.php` if absent)

**Approach**:

Accept an `ids` REST parameter (array or comma-separated string of positive integers). When `ids` is non-empty, use `post__in` in the `get_posts()` call instead of `offset`/`posts_per_page`. When `ids` is empty or absent, existing pagination behaviour is unchanged. Cap the array at 200 IDs to match the existing `per_page` ceiling. Return the same attachment data shape as the paginated path.

**Patterns to follow**: The existing `blog_id_args` validation pattern in `SourceEndpoints::register_routes()`; sanitise each element with `absint` and filter out zeros.

**Test scenarios**:
- Request with no `ids` param → returns paginated results as before.
- Request with `ids=[5,12,99]` → returns only the three specified attachments (in any order), regardless of offset param.
- Request with `ids` containing a non-existent ID → returns the items that do exist; the missing ID is silently omitted.
- Request with `ids=[]` or `ids` omitted → falls through to offset pagination.
- Request with more than 200 IDs → list is truncated to 200.

**Verification**: `SourceClient::get()` call with `ids` param returns only the requested attachments by ID.

---

### U2. Track failed media items and queue a targeted retry

**Goal**: Collect attachment IDs that fail during a batch and schedule a follow-up AS action to retry only those items, with exponential backoff and permanent logging on exhaustion.

**Requirements**: R1, R2

**Dependencies**: U1

**Files**:
- `includes/destination/class-media-importer.php` (modify)
- `tests/test-media-importer.php` (extend)

**Approach**:

Add a `source_attachment_ids` parameter to `hbm_import_media`. When this parameter is present and non-empty, the action is a targeted retry run:
- Call `SourceClient::get()` with `ids = $source_attachment_ids` instead of `offset`/`per_page`.
- Do not advance `stage_offset` and do not schedule the next offset batch — this is a patch pass only.

During the main loop (both normal and retry runs), explicitly collect `$failed_source_ids` — the source attachment IDs where `download_url()`, `wp_handle_sideload()`, or `wp_insert_attachment()` returned an error. Only IDs with a known `source_att_id` (> 0) are tracked; items that fail the SSRF check or have no file_url are permanently skipped without retry.

After the loop:

```
if non-empty $failed_source_ids:
  if $attempt < hbm_max_retries:
    schedule hbm_import_media with source_attachment_ids=$failed_source_ids,
    attempt=$attempt+1, exponential delay (60 * 2^attempt seconds)
  else:
    append "N media items permanently failed: [IDs]" to site_job error_message
    (non-blocking — site_job status remains 'complete' for the overall job)
```

The `stage_offset` update and the next-batch dispatch logic remain unchanged for normal (offset-based) runs.

**Patterns to follow**: `PipelineController::handle_batch_failure()` for the backoff formula; `IdMap::get()` idempotency check pattern already in the loop.

**Test scenarios**:
- Batch of 3 items; item 2 fails `download_url()`. After loop: `$failed_source_ids = [source_id_2]`. A retry AS action is scheduled with `source_attachment_ids=[source_id_2]` and `attempt=1`.
- Retry run with `source_attachment_ids` set: `SourceClient::get()` is called with `ids` param, not offset; `stage_offset` is not updated; next-batch action is not enqueued.
- Retry succeeds: item is imported normally, IdMap entry is set, `$failed_source_ids` is empty after retry loop, no further retry scheduled.
- Retry fails at `attempt = hbm_max_retries`: no new retry scheduled; `error_message` on site_job is appended with the permanently-failed IDs; site_job status remains unchanged.
- Item with no `source_att_id` or that fails SSRF check: not added to `$failed_source_ids`, no retry.
- Item already in IdMap: skipped by idempotency check, not added to `$failed_source_ids`.

**Verification**: Run a migration where 2 attachments have unreachable URLs (mock `pre_http_request`). Confirm a retry AS action is enqueued. After max retries, confirm `error_message` on the site_job contains the source IDs.

---

### U3. Suppress email notifications during user import

**Goal**: Prevent any email from being sent while `UserImporter` creates new users, blocking both core and plugin-driven notifications.

**Requirements**: R3

**Dependencies**: None

**Files**:
- `includes/destination/class-user-importer.php` (modify)
- `tests/test-user-importer.php` (extend)

**Approach**:

Immediately before the `foreach ( $users as $u )` loop, register a closure on the `pre_wp_mail` filter that returns a `WP_Error`. This causes `wp_mail()` to short-circuit without sending. Remove the filter by reference immediately after the loop (including in the `catch` block's `finally`-equivalent cleanup path).

The filter scope is tight: it wraps only the user-creation loop, not the batch setup or the subsequent role-enqueue code.

**Patterns to follow**: The `upload_dir` one-shot filter pattern in `MediaImporter::upload_dir_filter_for_date()` — register by closure reference, remove by same reference.

**Test scenarios**:
- `wp_mail` is not called during `wp_insert_user()` when the filter is active: assert `wp_mail` spy is never invoked during the loop.
- Filter is removed after loop completes normally: `apply_filters('pre_wp_mail', null, [])` returns null (no active suppression) after `process()` returns.
- Filter is removed after loop throws an exception: same assertion holds after the catch block runs.
- Users are still created successfully with the filter active (the filter only suppresses mail, not insert logic).

**Verification**: In a test environment with a `wp_mail` spy hooked to `wp_mail`, run `UserImporter::process()` with a mock source returning new users. Assert the spy records zero calls.

---

### U4. Persist and display migration history

**Goal**: Save a summary of every migration — complete or cleared — to `hbm_migration_history` site option on the source, and display past migrations on the admin page.

**Requirements**: R4, R5, R6

**Dependencies**: None

**Files**:
- `includes/source/class-site-index.php` (modify — save history on completion)
- `includes/admin/class-admin-page.php` (modify — save on clear, store `started_at`, render history)
- `assets/css/admin.css` (extend — history table styles)
- `tests/test-site-index.php` (extend)
- `tests/test-admin-page.php` (extend)

**Approach**:

**Data shape** for each history entry (stored as array in `hbm_migration_history`):
```
migration_id  int
dest_url      string
started_at    int  (Unix timestamp)
saved_at      int  (Unix timestamp — when this entry was written)
status        string  ('complete', 'failed', 'cancelled', 'running', 'unknown')
sites         array of { source_domain, dest_path, status, error_message }
```

**`handle_start_migration()`** — add `'started_at' => time()` to the `hbm_active_migration` array before writing it. No other changes.

**`SiteIndex::proxy_migration_status()`** — after the existing `dest_key` strip-on-complete block, call a shared `save_history_entry()` helper when `status === 'complete'`. The helper reads `hbm_active_migration` for `migration_id`, `dest_url`, and `started_at`, builds the entry from the destination response body, prepends it to `hbm_migration_history`, and trims to 10 entries. Guard against duplicate saves (same `migration_id` already in history).

**`handle_clear_migration()`** — before `delete_site_option('hbm_active_migration')`, fetch the current destination status using the same `wp_remote_get()` pattern as `proxy_migration_status()`. Pass the response body (or `['status' => 'unknown', 'sites' => []]` on failure) to `save_history_entry()`. Then delete the site option as before.

**`save_history_entry()`** — private static helper on `AdminPage` (or a standalone function in an appropriate class). Reads `hbm_active_migration` for metadata, builds the entry, prepends, trims, saves. Returns early (no-op) if no active migration exists or if `migration_id` is already in history.

**`render_page()`** — read `hbm_migration_history`. If non-empty, render a "Past Migrations" section below the config form as a `<table>` with columns: Date, Destination, Status, Sites. Each row is expandable (or a detail sub-row) showing per-site status and error messages. Render above the pre-flight/site-select block so it is always visible regardless of config state.

**Patterns to follow**: The existing `$active` site option read/write pattern in `proxy_migration_status()`. The `hbm-status-*` CSS classes already in `admin.css` for status badges.

**Test scenarios (SiteIndex)**:
- `proxy_migration_status()` returns `status: complete` → a history entry is saved with correct `migration_id`, `dest_url`, `started_at`, `saved_at`, `status: complete`, and `sites` from the response body.
- Called a second time with the same `migration_id` → no duplicate entry is added.
- History already has 10 entries → oldest is dropped, new entry is prepended.

**Test scenarios (AdminPage)**:
- `handle_clear_migration()` with reachable destination → fetches status, saves history entry, then deletes `hbm_active_migration`.
- `handle_clear_migration()` with unreachable destination → saves a `status: unknown` entry, still deletes `hbm_active_migration`.
- `handle_start_migration()` → `hbm_active_migration` written with a `started_at` int.
- `render_page()` with two history entries → HTML contains both `migration_id` values in the Past Migrations table.
- `render_page()` with empty history → no Past Migrations section rendered.

**Verification**: Run two migrations sequentially (or mock the site option). Confirm both appear in the Past Migrations table after clearing. Confirm that starting a third migration does not remove earlier history entries.

---

## Scope Boundaries

### In scope
- Individual media attachment retry (R1, R2)
- Email suppression during user creation (R3)
- Migration history persistence and display (R4, R5, R6)

### Deferred to Follow-Up Work
- Exposing history via a REST endpoint (useful for a future dashboard or CLI status command)
- History on the destination side (the `hbm_migrations` table already holds this; exposing it is a separate UI concern)
- Per-attachment error detail in the history (current plan logs IDs only; full error messages per ID would require schema changes)
- Retry for failures in other importers (posts, terms, options) — the same pattern applies but is lower priority given the failure rates observed

---

## Risks & Dependencies

| Risk | Mitigation |
|------|-----------|
| `pre_wp_mail` suppresses legitimate mail if the filter scope leaks | Remove filter by reference in both the normal and exception paths; keep the scope tight to the user loop only |
| `save_history_entry()` called on every successful status poll, not just first completion | Guard with `migration_id` deduplication before writing |
| History grows unbounded under aggressive polling | Cap at 10 entries with explicit trim after prepend |
| Destination unreachable at Clear time → missing history data | Graceful fallback to `status: unknown` with empty `sites`; the entry is still saved so the migration appears in history |
| `started_at` absent from migrations started before this fix ships | Guard with `?? 0` when reading; history entry shows `0` timestamp which renders as "unknown date" in the UI |
