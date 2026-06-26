---
title: "fix: Attachment doubling, email suppression gaps, and site slug sanitization"
date: 2026-06-26
type: fix
status: shipped
canonical: docs/plans/2026-06-26-001-fix-attachment-doubling-email-site-slug-plan.md
---

## Summary

Three distinct bugs in the migration pipeline: (1) every attachment post is imported twice — once as a hollow record by the posts stage and once as a properly sideloaded file by the media stage — causing 706 source items to produce 1408 destination items, each second copy having a `-1` filename suffix; (2) WordPress notification emails are sent when new blogs are created and users are assigned to them, because `class-term-importer.php` lacks the `pre_wp_mail` suppression already present in `class-user-importer.php`; (3) destination subsite paths contain dots (e.g., `/harmsboone.org/`) which WordPress rejects or treats as file extensions on Nginx subdirectory multisites.

---

## Problem Frame

**Attachment doubling.** `class-post-reader.php` issues `SELECT * FROM {$wpdb->posts} WHERE ID > %d ORDER BY ID LIMIT %d` with no `post_type` filter, so the posts endpoint returns all 706 attachment posts alongside regular posts and pages. `class-post-importer.php` faithfully creates database records for all of them via `wp_insert_post()`, storing `_wp_attached_file` meta from the source (imported as raw postmeta). These are "hollow" attachments — the physical files are never downloaded. When the media stage later runs `wp_handle_sideload()` for the same items, `wp_unique_filename()` finds the existing `_wp_attached_file` database entry and appends `-1` to the filename, producing a second attachment post with a corrupted path. Both the hollow record and the real record remain on the destination.

**Email notifications.** `class-user-importer.php` suppresses `wp_mail` via `pre_wp_mail` during user creation (commits 4128535). `class-term-importer.php` has no equivalent suppression. WordPress fires email notifications when a new multisite blog is created (`wpmu_new_blog` chain → `wp_mail`) and when a user is added to a blog (`add_user_to_blog` → various hooks → `wp_mail`). Both happen unconditionally during migration.

**Site slug dots.** `MultisiteHandler::dest_path_for_siteurl()` has a fast path that strips the base domain to produce clean slugs (e.g., `greg.harmsboone.org` on network `sites.harmsboone.org` → `/greg/`). When that fast path cannot apply (multi-label subdomain, or source domain is the bare base domain), the fallback at line 39 returns the full hostname verbatim: `/harmsboone.org/`. WordPress multisite rejects site paths containing dots; Nginx subdirectory setups treat dotted path segments as file extensions.

---

## Requirements

- R1: Attachment posts must not be created during the posts stage; only the media stage creates them with proper file sideloading.
- R2: No WordPress notification email is sent as a result of blog creation during migration.
- R3: No WordPress notification email is sent as a result of user-to-blog role assignment during migration.
- R4: Destination subsite paths produced by `dest_path_for_siteurl()` must not contain dots.
- R5: The intentional migration-completion email (sent in `class-search-replace.php`) must continue to work.

---

## Key Technical Decisions

**KTD-1: Filter attachments at source (SQL) and add a destination guard**
The primary fix is source-side: add `AND post_type != 'attachment'` to the SQL in `class-post-reader.php`. This ensures attachment posts are never transmitted over the wire as "posts" data. A secondary guard in `class-post-importer.php` (`continue` on `post_type === 'attachment'`) provides defense-in-depth against old source plugin versions or edge cases. Both changes are needed.

**KTD-2: `pre_wp_mail` suppression follows the existing per-operation pattern**
`class-user-importer.php` adds/removes a `pre_wp_mail` filter bracketing the user-creation loop. The same pattern applies to `create_subsite()` and `assign_user_roles()` in `class-term-importer.php`. A global migration-wide `pre_wp_mail` filter would also suppress the intentional completion email in `class-search-replace.php`, so stage-scoped suppression is the right approach.

**KTD-3: Dot-to-dash replacement on the fallback hostname only**
Only the fallback return path in `dest_path_for_siteurl()` (line 39) produces dotted paths. The fast-path (`/greg/`, `/greg/store/`) already produces clean slugs and must not be changed. `str_replace('.', '-', $host)` on the fallback hostname is the minimal change. Path components after the hostname (e.g., `/store/`) are not sanitized — they're controlled by the source site's URL structure and rarely contain dots.

---

## Implementation Units

### U4. Exclude attachment posts from the posts pipeline

**Goal:** Stop `class-post-reader.php` from returning attachment posts, and add a guard in `class-post-importer.php` so no hollow attachment records are created even if the source sends them.

**Requirements:** R1

**Dependencies:** None

**Files:**
- `includes/source/class-post-reader.php` (modify — SQL filter)
- `includes/destination/class-post-importer.php` (modify — destination guard)
- `tests/test-post-importer.php` (add tests)

**Approach:**

*Source side:* Change the SQL in `class-post-reader.php` from a bare `WHERE ID > %d` to also exclude `post_type = 'attachment'`. The query already uses `wpdb->prepare()`, so adding an additional literal condition is straightforward. Do not exclude other internally-managed post types (revisions, nav menu items, etc.) in this pass — scope this fix to attachments only.

*Destination side:* In the `foreach ( $posts as $p )` loop in `class-post-importer.php`, add an early `continue` when `$p['post_type'] === 'attachment'` before any database work. This runs before the `IdMap::get()` check so no premature IdMap write occurs.

**Patterns to follow:**
- `class-post-reader.php` SQL at lines 16–20: extend `WHERE` clause preserving the `$last_id` cursor pagination
- `class-post-importer.php` existing early-continue at line 100 (WP_Error guard): same `continue` shape

**Test scenarios:**
- Source guard: attachment posts returned by SQL before the fix no longer appear in results after — verify by checking returned post types do not include `attachment`
- Destination guard: if a batch payload containing an attachment post type is processed by `PostImporter::process()`, no attachment post is created in the destination, no IdMap entry is set for that item, and the non-attachment posts in the same batch ARE created correctly
- No regression: a batch containing only regular posts and pages is imported normally (no items silently dropped)
- IdMap safety: the attachment item's source ID does not appear as a `post` type entry in IdMap after the import run (verifies the guard fires before `IdMap::set()`)

**Verification:** Run a migration from a source with known attachment count; confirm destination attachment count equals the source attachment count (all from media stage only), with no hollow attachment records bearing source-site file paths in `_wp_attached_file`.

---

### U5. Suppress email during blog creation and user-to-blog assignment

**Goal:** Prevent WordPress from sending admin/user notification emails during the term/subsite stage.

**Requirements:** R2, R3, R5

**Dependencies:** None

**Files:**
- `includes/destination/class-term-importer.php` (modify)
- `tests/test-term-importer.php` (add tests)

**Approach:**

In `create_subsite()`, apply a `pre_wp_mail` suppression filter immediately before the first `wp_insert_site()` call and remove it after the last one (including the `generate_new` retry loop). The suppression callable should be named and consistent — reuse the same anonymous-function-stored-in-variable pattern from `class-user-importer.php` (lines 38–44) so the `remove_filter` is reliable.

In `assign_user_roles()`, bracket the `add_user_to_blog()` call (line 132) with the same add/remove pattern.

Both `create_subsite()` and `assign_user_roles()` are private static methods called together inside `TermImporter::process()` when `dest_blog_id` is null. The suppression scopes are tightly bounded — each method adds and removes its own filter, so no filter leaks across invocations.

**Patterns to follow:**
`class-user-importer.php` lines 38–44: `$suppress_mail = static function(): \WP_Error { return new \WP_Error(...); }; add_filter('pre_wp_mail', $suppress_mail); ... remove_filter('pre_wp_mail', $suppress_mail);`

**Test scenarios:**
- Blog creation: when `create_subsite()` runs, `wp_mail` is not called — assert the `pre_wp_mail` filter captures and short-circuits any mail attempts triggered by `wp_insert_site()`
- User assignment: when `assign_user_roles()` runs with valid user/blog IDs, `wp_mail` is not called
- Suppression removed after: after `create_subsite()` returns, the `pre_wp_mail` filter is no longer registered — verify with `has_filter()`
- Exception path: if `create_subsite()` throws, ensure the filter is still removed (currently there's no try/finally, so check whether the throw path leaks the filter — if so, add cleanup)
- No regression: the intentional completion email in `class-search-replace.php` fires after migration is complete, outside these scoped suppressions

**Verification:** Trigger a migration in a test environment that creates a new subsite; confirm the network admin's inbox receives no "new site registered" notification and user inboxes receive no "added to site" notification.

---

### U6. Replace dots with dashes in fallback destination site paths

**Goal:** Ensure `dest_path_for_siteurl()` never produces a path containing dots, which WordPress multisite rejects.

**Requirements:** R4

**Dependencies:** None

**Files:**
- `includes/class-multisite-handler.php` (modify — line 39)
- `tests/test-multisite-handler.php` (update 8 test expectations, rename 1 test method)

**Approach:**

Change line 39 of `dest_path_for_siteurl()` from:
```
return '/' . $host . $path . '/';
```
to apply `str_replace('.', '-', $host)` on the hostname only. The `$path` segment (subdirectory portion of the source URL, e.g., `/store`) is left unchanged — it's user-controlled and rarely contains dots.

The fast path at lines 31–36 (clean subdomain stripping) is unaffected — it returns before line 39.

**Test expectation updates** (8 tests in `tests/test-multisite-handler.php`):

| Test | Old expected | New expected |
|---|---|---|
| `test_dest_path_bare_domain` | `/example.com/` | `/example-com/` |
| `test_dest_path_subdomain` | `/news.example.com/` | `/news-example-com/` |
| `test_dest_path_subdirectory` | `/example.com/store/` | `/example-com/store/` |
| `test_dest_path_subdirectory_trailing_slash` | `/example.com/store/` | `/example-com/store/` |
| `test_dest_path_nested_subdirectory` | `/example.com/a/b/` | `/example-com/a/b/` |
| `test_multi_label_subdomain_not_stripped_to_avoid_dotted_slug` | `/news.greg.harmsboone.org/` | `/news-greg-harmsboone-org/` |
| `test_base_domain_not_stripped` | `/harmsboone.org/` | `/harmsboone-org/` |
| `test_no_network_domain_falls_back_to_full_host` | `/greg.harmsboone.org/` | `/greg-harmsboone-org/` |

Rename `test_multi_label_subdomain_not_stripped_to_avoid_dotted_slug` → `test_multi_label_subdomain_sanitized_to_dashed_slug` (the old name documents the bug being fixed).

Unaffected tests (fast path / clean subdomain stripping): `test_subdomain_stripped_to_slug_when_network_domain_matches`, `test_subdomain_with_subdirectory_stripped`, `test_network_domain_is_bare_base_domain`.

**Test scenarios:**
- Bare domain: `harmsboone.org` → `/harmsboone-org/`
- Subdomain fallback: `news.greg.harmsboone.org` → `/news-greg-harmsboone-org/`
- No network domain supplied: `greg.harmsboone.org` → `/greg-harmsboone-org/`
- Fast path unaffected: `greg.harmsboone.org` on `sites.harmsboone.org` → still `/greg/`
- Subdirectory preserved: `example.com/store` → `/example-com/store/`

**Verification:** After deploying, run a migration for `harmsboone.org`; confirm the destination path is `/harmsboone-org/` and `wp_insert_site()` succeeds without a validation error.

---

### U7. Expose media conflict policy as a user-selectable option in the admin UI

**Goal:** Let users choose whether to import all media (duplicates allowed) or skip attachments whose filename already exists on the destination, instead of always defaulting to `import_all`.

**Requirements:** User-facing configuration for `media_conflict_policy`.

**Dependencies:** None

**Files:**
- `includes/admin/class-admin-page.php` (modify — replace hidden input with visible control)

**Approach:**

In `class-admin-page.php` at line 173, replace:
```html
<input type="hidden" name="media_conflict_policy" id="hbm-media-policy" value="import_all">
```
with a visible radio button group or `<select>` offering two options:
- **Import all** (`import_all`) — always create a new attachment, even if a file with the same name exists on the destination. Default.
- **Skip existing** (`skip_duplicates`) — if an attachment with the same filename already exists on the destination, reuse it instead of downloading again.

The form handler at line 268, the POST body at line 286, and all downstream pipeline code already read and honor this field correctly. No backend changes needed.

Place the control near the migration-start form, labelled clearly so users understand it applies to media only. Match the existing form's style/structure for consistency.

**Test scenarios:**
- Default behavior: when no selection is made, `import_all` is submitted and received by the backend
- Explicit skip: when `skip_duplicates` is selected, the POST body contains `media_conflict_policy=skip_duplicates`
- Validation: only `import_all` and `skip_duplicates` are accepted values; any other value falls back to `import_all` (existing sanitize_key + default in form handler at line 268)

**Verification:** Open the migration admin page, confirm the media conflict option is visible; start a migration with "Skip existing" selected; verify the migration record has `media_conflict_policy = skip_duplicates`.

---

### U8. Add "attached media only" import scope option

**Goal:** Let users restrict the media import to only attachments that are attached to a post (`post_parent > 0`), skipping orphan media files that aren't referenced by any post.

**Requirements:** New `media_import_scope` setting threaded through DB schema, admin UI, API request, and source media reader.

**Dependencies:** None

**Files:**
- `includes/class-queue-table.php` (modify — add column)
- `includes/class-migration-registry.php` (modify — create/get include new field)
- `includes/destination/class-migration-receiver.php` (modify — accept new param)
- `includes/admin/class-admin-page.php` (modify — add UI control, include in POST body)
- `includes/source/class-media-reader.php` (modify — filter query when `attached_only`)
- `includes/destination/class-media-importer.php` (modify — pass scope to source API)
- `tests/test-media-reader.php` (add tests)

**Approach:**

**DB schema:** Add `media_import_scope varchar(20) NOT NULL DEFAULT 'all'` to the `hbm_migrations` table in `class-queue-table.php`. `dbDelta()` handles the column addition for existing installations.

**Registry:** In `class-migration-registry.php`, include `media_import_scope` in `create_migration()` and in the object returned by `get_migration()`, following the same shape as `media_conflict_policy` (line 23 and line 34).

**Receiver:** In `class-migration-receiver.php`, accept `media_import_scope` from the request (line 173 area), sanitize with `sanitize_key()`, default to `'all'`, pass through to `create_migration()`.

**Admin UI:** Add a radio group or select to the migration-start form alongside the media conflict policy control:
- **All media** (`all`) — import every attachment from the source. Default.
- **Attached to a post only** (`attached_only`) — import only attachments with a parent post.

Add `media_import_scope` to the JSON POST body (line 279-287 in `class-admin-page.php`).

**Source media reader:** In `class-media-reader.php`, accept a new `attached_only` request parameter. When it is truthy and no specific `ids` are requested, add a `posts_where` filter for the duration of the `get_posts()` call that appends `AND {$wpdb->posts}.post_parent > 0` to the SQL. Remove the filter immediately after the call. The `ids`-based retry path ignores this filter — specific attachment IDs are always fetched regardless of scope.

**Destination media importer:** In `class-media-importer.php`, when building the non-retry API request params (line 43), include `'attached_only' => ('attached_only' === ($migration->media_import_scope ?? 'all')) ? 1 : 0`.

**Patterns to follow:**
- `media_conflict_policy` threading (DB → registry → receiver → admin → importer): exact same pattern
- `posts_where` filter pattern: use a named variable so `remove_filter()` can reference the exact closure

**Test scenarios (source media reader):**
- Default scope: no `attached_only` param → orphan attachments (post_parent=0) are returned
- Attached only: `attached_only=1` → only attachments with post_parent > 0 are returned; orphan attachments are excluded
- IDs retry pass: when `ids` param is set, `attached_only` is ignored — specific IDs are always returned
- Filter cleanup: after `get_posts()` returns, the `posts_where` filter is not registered

**Test scenarios (integration):**
- Migration with `media_import_scope=attached_only`: confirm destination attachment count equals source "attached" attachment count (not total)
- Migration with `media_import_scope=all` (default): no change in existing behavior

**Verification:** Configure a migration with "Attached to a post only" selected; confirm the destination receives only media items whose `post_parent > 0` on the source.

---

## Scope Boundaries

**In scope:** posts-endpoint attachment filter, term-importer email suppression, multisite-handler slug sanitization, media conflict policy UI, media import scope option.

**Out of scope:**
- Other unfiltered post types in the posts endpoint (revisions, nav_menu_items) — separate concern, not causing active bugs
- Global migration-wide `pre_wp_mail` filter — would suppress the intentional completion email; stage-scoped suppression is sufficient
- Sanitizing the path component (`$path`) of destination slugs — path segments after the hostname are not known to contain dots in practice
- Subdirectory path-segment sanitization for source sites with dotted subdirectory paths (e.g., `/v1.2/`) — deferred; not reported

### Deferred to Follow-Up Work

- Audit other post types (revisions, nav_menu_items, customize_changesets) that may be inadvertently imported via the unfiltered posts endpoint
- Consider a global `pre_wp_mail` suppression wrapper at the migration-job level that exempts only the intentional completion email call site

---

## Risks & Dependencies

- `class-post-reader.php` SQL change is source-side. Sites running the old source plugin version will still send attachment posts over the API. The destination guard in U4 provides defense-in-depth for those cases.
- U6 changes the contract of `dest_path_for_siteurl()` — all callers get dashed paths instead of dotted ones. The only caller is `class-migration-receiver.php` line 180. Existing `hbm_site_jobs` rows in the DB may have dotted `dest_path` values from pre-fix migrations; these are inert for migrations that have already completed.
- The `pre_wp_mail` suppression in U5 uses `remove_filter()` in the happy path. If `create_subsite()` throws before the `remove_filter()` call, the filter leaks across the catch block. Existing code in `class-term-importer.php` does not use try/finally. Add cleanup in the exception path or restructure to use try/finally.
