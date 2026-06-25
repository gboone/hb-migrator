---
title: "fix: Repair broken attachment imports without creating duplicate posts"
date: 2026-06-25
type: fix
status: draft
---

## Summary

Attachment posts can be created with empty/broken metadata when `wp_generate_attachment_metadata()`
fails silently. IdMap records these as successful imports, so retries are skipped and the broken
attachment persists. On a subsequent re-run, the old file is still on disk, causing
`wp_handle_sideload()` to auto-deduplicate with a `-1` filename suffix and create a second
attachment with a wrong filename. The fix validates metadata after generation (deleting and retrying
broken attachments) and adds a durable cross-run marker so re-runs find and evaluate old
attachments rather than creating duplicates.

---

## Problem Frame

`wp_generate_attachment_metadata()` returns `[]` or `false` on image-processing failure. The
current code stores that result unconditionally and calls `IdMap::set()` — marking the
attachment as successfully imported even though the image is broken.

On re-run (clear + new migration), the IdMap is fresh (new `site_job_id`) but the file from the
previous run still exists on disk. `wp_handle_sideload()` finds the file and appends a `-1`
suffix, producing a second attachment post with the wrong filename.

**User expectation:** retry the file upload only if the upload failed; only retry attachment
creation if the post was never created.

---

## Requirements

- R1: `wp_generate_attachment_metadata()` returning empty or false must be treated as a failure; the attachment post must be deleted and the item queued for retry.
- R2: On re-run, an attachment post created by a previous import of the same source attachment must be detected before attempting a fresh download.
- R3: On re-run, if the detected existing attachment has valid (non-empty) metadata, it must be recorded in the current IdMap and skipped — no re-download, no duplicate.
- R4: On re-run, if the detected existing attachment has broken metadata, it must be deleted cleanly so the re-import produces the original filename (no `-1` suffix).

---

## Key Technical Decisions

**KTD-1: `_hbm_source_attachment_id` post meta as the cross-run marker**
IdMap is per-`site_job_id` and is cleaned up by `complete_migration()`. A new run has a
new site_job and sees no prior IdMap entries. Post meta on the destination attachment persists
regardless. This meta key is precise — it only matches attachments imported from the exact
source attachment ID, unlike `post_name` lookup which could false-match pre-existing content.

**KTD-2: Respond to broken metadata by deleting the attachment**
In-place file repair (construct correct upload path, re-run `wp_generate_attachment_metadata`)
is fragile on object-storage filesystems. Deleting the attachment with `wp_delete_attachment($id, true)`
removes post, file, and thumbnails atomically and falls back to the existing retry path unchanged.
Since the file is gone, no `-1` suffix conflict on re-sideload.

**KTD-3: Detect empty vs. false with `empty()`**
`wp_generate_attachment_metadata()` returns `[]` on partial failure and `false` on total
failure. `empty($meta)` handles both in one check.

---

## Implementation Units

### U1. Store `_hbm_source_attachment_id` post meta after successful attachment insert

**Goal:** Persist a durable cross-run marker on each destination attachment so subsequent
migration runs can find attachments created by previous runs.

**Requirements:** R2

**Dependencies:** None

**Files:**
- `includes/destination/class-media-importer.php` (modify)

**Approach:**
After `wp_insert_attachment()` succeeds (before `wp_generate_attachment_metadata()` at
the current line 147), call `update_post_meta($dest_att_id, '_hbm_source_attachment_id', $source_att_id)`.
Runs inside `switch_to_blog($job->dest_blog_id)` — correct subsite context.
When U2 calls `wp_delete_attachment($dest_att_id, true)` on metadata failure, WordPress
deletes all post meta automatically. No separate cleanup needed.

**Patterns to follow:**
`update_post_meta($dest_att_id, '_wp_attachment_image_alt', $att['alt_text'])` — same shape, same location in the loop.

**Test scenarios:**
- Happy path: after `wp_insert_attachment` succeeds, `get_post_meta($dest_att_id, '_hbm_source_attachment_id', true)` equals `$source_att_id`
- Insert failure: `wp_insert_attachment` returns WP_Error → `continue` fires → meta call never reached
- Cleanup: mock `wp_generate_attachment_metadata` returning empty → `wp_delete_attachment` fires (U2) → `get_post_meta` on deleted post returns empty

**Verification:** After a successful per-item import, query `_hbm_source_attachment_id` on
the destination attachment; value matches the source attachment ID.

---

### U2. Treat empty/false metadata as failure; delete attachment and retry

**Goal:** Prevent broken attachments from being recorded in IdMap as successful imports.

**Requirements:** R1

**Dependencies:** U1 (meta stored before this runs; `wp_delete_attachment` cleans it up)

**Files:**
- `includes/destination/class-media-importer.php` (modify)
- `tests/test-media-importer.php` (add tests)

**Approach:**
After `wp_generate_attachment_metadata($dest_att_id, $sideload['file'])`, check if `$meta`
is empty/false. If so, delete the attachment and fail the item:

```
if ( empty( $meta ) ) {
    wp_delete_attachment( $dest_att_id, true );
    if ( $source_att_id ) {
        $failed_items[ $source_att_id ] = 'metadata generation failed — image may be corrupt or unprocessable';
    }
    continue;
}
```

`continue` skips the `IdMap::set()` call that follows. The exponential-backoff retry mechanism
(already in place via `as_schedule_single_action`) re-attempts the full download → sideload →
insert → metadata cycle. After retry exhaustion the item falls into the permanent failure log
with this reason string.

Use `wp_delete_attachment($id, true)` rather than bare `@unlink` because the attachment post
already exists. This removes the post, its file, and thumbnails in one call.

**Patterns to follow:**
Existing `@unlink()` + `$failed_items[...] = '...'` + `continue` at lines 114–119 (sideload
failure) and 139–145 (insert failure).

**Test scenarios:**
- Happy path: `wp_generate_attachment_metadata` returns non-empty array → `IdMap::set()` fires, no deletion
- Empty array failure: mock returns `[]` → `wp_delete_attachment` called, item in `$failed_items`, no IdMap entry
- False failure: mock returns `false` → same behavior as empty array
- Retry exhaustion: item appears in `$job->error_message` with 'metadata generation failed' reason string
- File cleanup: after `wp_delete_attachment`, the sideloaded file is gone (no orphan on disk for re-run to conflict with)

**Verification:**
Mock `wp_generate_attachment_metadata` to return `false`; run MediaImporter; confirm no IdMap
entry for the item and no attachment post; check `$job->error_message` after retry exhaustion
contains the metadata failure reason.

---

### U3. Pre-import cross-run lookup by `_hbm_source_attachment_id`

**Goal:** Prevent re-runs from creating `-1` filename duplicates by detecting and evaluating
attachments left by previous migration runs before attempting a fresh download.

**Requirements:** R2, R3, R4

**Dependencies:** U1

**Files:**
- `includes/destination/class-media-importer.php` (modify)
- `tests/test-media-importer.php` (add tests)

**Approach:**
Insert a lookup block after the existing `IdMap::get()` check (line 61) and before
`download_url()`. This already runs inside `switch_to_blog($job->dest_blog_id)`.

```
$existing = get_posts([
    'post_type'   => 'attachment',
    'post_status' => 'any',
    'numberposts' => 1,
    'fields'      => 'ids',
    'meta_key'    => '_hbm_source_attachment_id',
    'meta_value'  => $source_att_id,
]);
if ( ! empty( $existing ) ) {
    $existing_id   = (int) $existing[0];
    $existing_meta = wp_get_attachment_metadata( $existing_id );
    if ( ! empty( $existing_meta ) ) {
        IdMap::set( $site_job_id, 'attachment', $source_att_id, $existing_id );
        continue;
    }
    wp_delete_attachment( $existing_id, true );
    // fall through to fresh download — file removed, no -1 suffix conflict
}
```

**Performance note:** One `get_posts` meta query per media item. Acceptable for the
current migration sizes (~1,400 items/subsite). Worth noting for very large libraries.

**Patterns to follow:**
`skip_duplicates` lookup at lines 72–78: `get_posts(['post_type' => 'attachment', 'name' => ...])` + `IdMap::set()` + `continue`.

**Test scenarios:**
- No prior import: `get_posts` returns empty → normal flow, no change in behavior
- Prior healthy import: found with non-empty metadata → `IdMap::set()` + skip, no download, no new attachment created
- Prior broken import: found with empty metadata → `wp_delete_attachment` fires, falls through to fresh import, resulting filename has no `-1` suffix
- Within-run idempotency: existing `IdMap::get()` check at line 61 fires before U3 → U3 block never reached, no redundant query
- Multiple matches: `numberposts: 1` takes the first; only first match evaluated

**Verification:**
1. Run migration; confirm healthy attachment. Clear (new site_job_id). Re-run. Confirm same `dest_att_id` in new IdMap, no new attachment post created.
2. Manually clear `_wp_attachment_metadata` post meta on a destination attachment (simulate broken). Re-run. Confirm old attachment deleted, new attachment has original filename (not `-1` suffix).

---

## Scope Boundaries

**In scope:** metadata validation, durable cross-run deduplication via post meta, delete-and-retry for broken attachments.

**Out of scope:**
- HTTP reachability check for VIP object storage scenarios (valid metadata but inaccessible CDN file) — deferred; requires per-item HTTP cost and different failure-signal design
- One-time repair tool for orphan attachments already on destination from pre-fix runs
- Changes to `skip_duplicates` policy behavior

---

## Risks & Dependencies

- `wp_delete_attachment($id, true)` on WordPress VIP must trigger object-storage deletion. If not, orphaned files remain, and a re-import `wp_handle_sideload` may still see a filename conflict. Low probability given VIP's filesystem integration but worth confirming.
- `_hbm_source_attachment_id` meta key: if another plugin coincidentally uses this key, U3 lookup could find false-positive matches. Unlikely given the `_hbm_` prefix convention.
- U3 meta query adds latency proportional to media library size. At 10,000+ items per subsite, consider whether batching or caching the results is warranted.
