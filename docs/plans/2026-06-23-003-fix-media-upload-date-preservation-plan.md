---
title: "fix: Preserve original upload date when importing media"
date: 2026-06-23
type: fix
status: ready
---

# fix: Preserve original upload date when importing media

## Summary

`MediaImporter` calls `wp_handle_sideload()` without directing it to the correct `YYYY/MM` upload subdirectory. WordPress defaults to the current month, so every imported attachment lands in `2026/06/` regardless of its original upload date. The `SearchReplace` pass replaces only the base upload URL (domain + `/wp-content/uploads/sites/N`), leaving post content with URLs that reference non-existent paths like `…/2026/04/vonnegut-cats.jpeg` while the file lives at `…/2026/06/vonnegut-cats.jpeg`.

The `post_date` field is already sent by `MediaReader` and available in the `$att` payload inside `MediaImporter`; it just needs to influence the upload directory before the sideload call.

---

## Problem Frame

`wp_handle_sideload()` determines the target directory by calling `wp_upload_dir()` internally, without an explicit timestamp. That function uses the current date unless a `$time` argument is passed — but `wp_handle_sideload()` offers no parameter to forward one. The only hook point is the `upload_dir` filter.

The `SearchReplace` replacement map is intentionally broad (`source_upload_url → dest_upload_url`), which is the correct design: once the upload path bug is fixed, source and destination will share the same `YYYY/MM` suffix and domain-only replacement will work correctly.

**Affected sites from the current migration**: `sites.harmsboone.org/greg` (blog_id 2). All media landed in `2026/06/` regardless of original upload month. Post content was search-replaced at the domain level only, leaving date paths pointing to the wrong location.

---

## Requirements

| ID | Requirement |
|----|-------------|
| R1 | Attachments imported by `MediaImporter` land in the `YYYY/MM` directory matching their source `post_date`. |
| R2 | When `post_date` is absent or unparseable, the current date is used (WordPress default preserved). |
| R3 | The `upload_dir` filter is always removed after `wp_handle_sideload()`, whether it succeeds or fails. |
| R4 | Existing broken image URLs on the `greg` subsite can be repaired with a one-time WP-CLI command. |

---

## Key Technical Decisions

**KTD1: `upload_dir` filter, not post-sideload rename.**
`wp_handle_sideload()` exposes no parameter for a target directory. Registering a one-shot `upload_dir` filter that overrides `subdir`, `path`, and `url` before the call — and removing it immediately after — is the idiomatic WordPress approach. The alternative (sideload to current month, then `rename()` + update `_wp_attached_file` + update `guid`) requires two filesystem ops and leaves the attachment in an inconsistent state momentarily if anything between them throws.

**KTD2: No change to `SearchReplace`.**
Once R1 is satisfied, future migrations place files at the same relative path as the source. The existing `source_upload_url → dest_upload_url` replacement correctly updates only the domain. The `YYYY/MM/filename` suffix is identical on both sides.

**KTD3: WP-CLI regex search-replace for data repair.**
The `YYYY/MM/` date segment is always 8 characters, so the regex replacement is length-preserving and will not corrupt serialized `meta_value` lengths. A `--dry-run` pass is recommended before applying. No `--precise` flag needed.

---

## Implementation Units

### U1. Preserve original upload date path in MediaImporter

**Goal**: Apply an `upload_dir` filter before `wp_handle_sideload()` that redirects the sideloaded file into the attachment's original `YYYY/MM` directory.

**Requirements**: R1, R2, R3

**Dependencies**: None

**Files**:
- `includes/destination/class-media-importer.php` (modify)
- `tests/test-media-importer.php` (extend)

**Approach**:

Before `download_url()`, derive the desired upload subdir from `$att['post_date'] ?? ''`. If the string is non-empty and `strtotime()` returns a valid timestamp, build the subdir as `YYYY/MM` using `gmdate('Y/m', $ts)`.

Register a closure-based `upload_dir` filter that sets `subdir`, `path`, and `url` to reflect this subdir. Hold the closure in a local variable so it can be removed by reference. Call `download_url()` and `wp_handle_sideload()` as before. Remove the filter unconditionally in a `finally`-style block (or ensure removal happens before every early-return path after `$sideload`).

When `post_date` is empty or unparseable, skip the filter entirely — WordPress's default behavior handles the fallback.

**Technical design** (directional sketch, not specification):

```
$subdir_filter = null;
$post_date = $att['post_date'] ?? '';
if ( $post_date ) {
    $ts = strtotime( $post_date );
    if ( $ts ) {
        $subdir = '/' . gmdate( 'Y/m', $ts );
        $subdir_filter = function( $dirs ) use ( $subdir ) { ... };
        add_filter( 'upload_dir', $subdir_filter );
    }
}
$tmp = download_url( $file_url, 60 );
if ( $subdir_filter ) {
    remove_filter( 'upload_dir', $subdir_filter );
}
```

**Patterns to follow**: `class-term-importer.php` — wraps subsite operations in `switch_to_blog()` + filter setup + cleanup in a consistent pattern. The `upload_dir` filter is already exercised in the test suite via `mock_media()`.

**Test scenarios**:

To test the directory selection without a real file sideload, extract a private static helper — `upload_dir_filter_for_date( string $post_date ): ?callable` — that builds and returns the filter closure (or `null` when not applicable). Test this helper via `ReflectionClass::setAccessible(true)`:

- `post_date = '2024/03/15 10:00:00'` → the returned filter, when applied and `wp_upload_dir()` called, sets `subdir` to `/2024/03`.
- `post_date = '2023/11/01 00:00:00'` → subdir is `/2023/11`.
- Empty `post_date` → helper returns `null`; no filter is applied.
- `post_date = 'not-a-date'` → helper returns `null` (graceful fallback).

Integration-level (multisite required, skip if not multisite): mock media endpoint returning an attachment with `post_date = '2024/03/15 10:00:00'` and a sideloadable file URL; after `MediaImporter::process()`, the created attachment's `_wp_attached_file` meta starts with `2024/03/`.

**Verification**: Given a real sideload (Docker/VIP environment), an attachment with `post_date = '2024/03/15 10:00:00'` produces `_wp_attached_file = '2024/03/filename.ext'` and the physical file exists at `wp-content/uploads/sites/N/2024/03/filename.ext`.

---

## Data Repair: Existing greg Subsite

The following WP-CLI command repairs the already-migrated `sites.harmsboone.org/greg` subsite. It is safe to run on a **fresh destination** where all uploads landed in `2026/06/` — the replacement is length-preserving (8 chars → 8 chars) and will not corrupt serialized meta.

**Step 1: Dry run**

```bash
wp --url=sites.harmsboone.org/greg search-replace \
  'sites\.harmsboone\.org/greg/wp-content/uploads/sites/2/[0-9]{4}/[0-9]{2}/' \
  'sites.harmsboone.org/greg/wp-content/uploads/sites/2/2026/06/' \
  --regex \
  wp_2_posts wp_2_postmeta wp_2_options \
  --dry-run
```

Review the output. Any row where the URL path contained a YYYY/MM segment other than `2026/06` will be listed.

**Step 2: Apply**

```bash
wp --url=sites.harmsboone.org/greg search-replace \
  'sites\.harmsboone\.org/greg/wp-content/uploads/sites/2/[0-9]{4}/[0-9]{2}/' \
  'sites.harmsboone.org/greg/wp-content/uploads/sites/2/2026/06/' \
  --regex \
  wp_2_posts wp_2_postmeta wp_2_options
```

**Scope note**: The command targets only `wp_2_posts`, `wp_2_postmeta`, and `wp_2_options`. The `guid` column is intentionally excluded — the attachment posts' guids were set by `wp_insert_attachment()` to the actual `2026/06/` path when the file was created, so they are already correct. The main site tables (`wp_posts`, etc.) are also excluded because the migrated content lives exclusively in the `wp_2_*` tables.

**If the regex module is unavailable** (some VIP environments restrict `--regex`): run a targeted replacement for each source month individually:

```bash
for month in 2025/01 2025/02 2025/03 2025/04 2025/05 2025/06 2025/07 2025/08 2025/09 2025/10 2025/11 2025/12 2026/01 2026/02 2026/03 2026/04 2026/05; do
  wp --url=sites.harmsboone.org/greg search-replace \
    "sites.harmsboone.org/greg/wp-content/uploads/sites/2/${month}/" \
    'sites.harmsboone.org/greg/wp-content/uploads/sites/2/2026/06/' \
    wp_2_posts wp_2_postmeta wp_2_options
done
```

---

## Scope Boundaries

### In scope
- `MediaImporter` upload date fix (R1–R3)
- Data repair runbook for the current `greg` subsite migration (R4)

### Deferred to Follow-Up Work
- Fixing `_wp_attached_file` meta and `guid` for attachments already imported with the wrong date path — these values are internally consistent (they correctly point to `2026/06/`), and post content URLs will be fixed by the repair command. Re-importing would be needed to move the physical files.
- Running the same repair for any other subsites that were migrated before this fix ships.

---

## Risks & Dependencies

| Risk | Mitigation |
|------|-----------|
| `upload_dir` filter leaks if an exception is thrown inside `wp_handle_sideload()` | Remove the filter before any throw-path exits `process()` — the existing `try/catch` in `process()` calls `restore_current_blog()` on failure; remove the filter in the same location |
| VIP environment may have custom `upload_dir` filtering that conflicts | The closure-based filter appends to existing filter chain; it overrides `subdir`/`path`/`url` only for the one sideload call, then is removed |
| `strtotime()` is locale/timezone sensitive | Use `gmdate()` + a UTC timestamp to derive the subdir, matching WordPress's own `wp_upload_dir()` behavior |
| WP-CLI `--regex` unavailable in some VIP environments | Fallback per-month loop provided in the repair runbook |
