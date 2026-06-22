---
date: 2026-06-22
topic: async-export-queue
---

# Async Export Queue with Resumable Stages

## Summary

A WordPress plugin installs on the source self-hosted site and runs the full migration export pipeline — SQL dump, WXR post export, and media archive packaging — as an async background queue backed by Action Scheduler. One button press auto-sequences all three stages; the queue checkpoints each batch to the database and auto-retries failures before surfacing errors. Export artifacts land in the uploads directory with protected wp-admin download links; the operator then runs `vip import sql` and `vip import media` to complete the migration.

---

## Problem Frame

PHP's 60-second HTTP timeout and memory limits are hard ceilings imposed by the source hosting environment — shared hosting, entry-level managed WordPress, and similar platforms. A synchronous migration run fails at whatever percentage it reached when the timeout fires, with no recovery path and no progress record. On sites with tens of thousands of posts or gigabytes of media, this makes synchronous export infeasible regardless of code quality.

An async queue decouples export duration from HTTP server limits. A batch that takes 5 seconds to process 500 posts safely completes under any timeout. Persisted checkpoint state converts a mid-run failure from a catastrophe into a recoverable position — the next batch picks up where the last one left off.

---

## Key Decisions

**Bundle Action Scheduler as a vendor dependency.** WP-Cron fires only on page loads, which means export progress is gated on traffic arriving at the source site — the wrong dependency for a site in the middle of being migrated away. Action Scheduler's persistent queue dispatches reliably regardless of traffic. Bundling it (rather than requiring a separate plugin install) removes the operator setup step; Action Scheduler's "best version wins" resolution handles version conflicts when WooCommerce or another plugin also bundles it.

**Auto-sequence stages, not manual gates.** The operator clicks once. The queue runs SQL → WXR → media in sequence without further input. Cancellation is available, but the happy path is hands-off. This reduces the migration from a multi-session operation to a single supervised run.

**Auto-retry failed batches, not pause-on-failure.** Action Scheduler retries failed batches automatically up to a configurable limit. The operator intervenes only when retries exhaust — they see a clear error and a "Retry Stage" action, not a stream of transient failure alerts for every network blip.

**Auto-detect multisite via `is_multisite()`.** The plugin detects network mode from WordPress core rather than requiring a manual toggle. An operator migrating a single subsite from a network will need to handle that as an exception; the standard export targets the full network.

**WXR and SQL are complementary artifacts.** The plugin produces both by default. The SQL dump is the primary VIP import artifact (used with `vip import sql`). The WXR export is supplementary — suited to WP Importer on the destination for selective content import or staging review. Operators choose which to use on the import side.

---

## Requirements

**Queue behavior**

- R1. The plugin manages the export pipeline as a persistent background queue backed by Action Scheduler, bundled as a vendor dependency within the plugin.
- R2. A single "Start Export" action triggers the full pipeline; stages run automatically in the sequence SQL → WXR → media without further operator input.
- R3. Each batch dispatches and completes within time bounds well under the 60-second HTTP timeout.
- R4. Each stage persists checkpoint state to a dedicated plugin database table; interrupted work resumes from the last completed batch, not from the start of the stage.
- R5. Failed batches are retried automatically up to a configurable limit (default: 3). When retries exhaust, the stage is marked failed, the pipeline halts, and the operator sees an error message and a "Retry Stage" action.
- R6. A "Reset Export" action clears all queue state and deletes partial artifact files, returning the plugin to its initial state for a clean re-run.

**Export stages**

- R7. The SQL stage exports the full database as a MySQL-compatible dump: InnoDB-only, `wp_` table prefix, no forbidden DDL (no `ALTER TABLE`, no triggers, no `ENGINE=MyISAM`).
- R8. The WXR stage streams post content in configurable batch sizes (default: 500 posts per batch), never loading the full XML document into memory at once.
- R9. The media stage packages upload files into a `.tar.gz` archive with `{year}/{month}/` folder structure at the root, no `uploads/` parent directory.
- R10. If total media volume exceeds 4 GB, the media stage splits output into multiple archives, each under 4 GB, with consistent internal structure across splits.

**Multisite**

- R11. In multisite mode, the SQL stage excludes network-level tables (`wp_users`, `wp_usermeta`, `wp_blogs`, `wp_site`, `wp_registration_log`, `wp_signups`, `wp_sitemeta`) and rewrites table prefixes from `wp_` to `wp_{blog_id}_`.
- R12. In multisite mode, the media stage rewrites upload paths from `wp-content/uploads/` to `wp-content/uploads/sites/{blog_id}/`.

**Security and access**

- R13. Export artifacts in the uploads directory are protected from direct URL access via `.htaccess` deny rules or equivalent, immediately on creation.
- R14. Artifact downloads are available only to authenticated WordPress administrators via wp-admin; no direct URL access.

**Progress UI**

- R15. A wp-admin page polls the queue database and displays per-stage progress counts (e.g., "4,200 of 12,000 posts exported").
- R16. When a stage fails after exhausting retries, the UI surfaces the error, the retry count, and a "Retry Stage" action that resumes from the last checkpoint.

---

## Key Flows

- F1. **Happy path export**
  - **Trigger:** Operator clicks "Start Export" in wp-admin.
  - **Steps:** Queue initializes → SQL batches run to completion → WXR batches run to completion → media batches run to completion → all artifacts available.
  - **Outcome:** Operator downloads SQL dump, WXR file, and media archive from wp-admin; runs `vip import sql` and `vip import media` via VIP CLI to complete the migration.

- F2. **Batch failure and auto-retry**
  - **Trigger:** A batch fails (timeout, OOM, database error).
  - **Steps:** Action Scheduler catches the failure → retries the batch (up to configured limit) → on exhausted retries, marks stage failed, halts pipeline, updates UI.
  - **Outcome:** Operator sees error + "Retry Stage" in wp-admin; retrying resumes from the last checkpointed batch, not from stage start.

- F3. **Reset and re-run**
  - **Trigger:** Operator clicks "Reset Export."
  - **Steps:** Plugin clears queue table entries, deletes partial artifact files.
  - **Outcome:** Plugin returns to initial state; operator can start a new export run.

---

## Acceptance Examples

- AE1. **Covers R5 — retry exhaustion.** Given retry limit is 3: a batch fails → Action Scheduler retries → second attempt fails → retries again → third attempt fails → stage marked failed, pipeline halts, UI shows error.
- AE2. **Covers R5 — transient failure recovery.** A batch fails once then succeeds on retry → pipeline continues from the next batch with no operator action.
- AE3. **Covers R11 — multisite SQL.** For a multisite export of blog ID 5: the SQL dump contains `wp_5_posts`, not `wp_posts`. The `wp_users` table is absent from the dump.
- AE4. **Covers R13 — artifact protection.** Immediately after the SQL dump file is created, a direct HTTP request to its uploads URL returns 403 (or equivalent deny response).
- AE5. **Covers R10 — large media split.** A media library totaling 9 GB produces three archives: two at just under 4 GB and one with the remainder, each with correct `{year}/{month}/` structure at root.

---

## Scope Boundaries

**Out of scope:**
- VIP import commands (`vip import sql`, `vip import media`) — the plugin produces files; the operator runs these separately via VIP CLI.
- Production import automation — VIP requires interactive domain confirmation; this cannot be automated.
- WP-CLI as the primary operator interface.
- Auto-uploading artifacts to VIP or any remote destination.

---

## Dependencies / Assumptions

- Source site has a writable `wp-content/uploads/` directory with sufficient disk space for the full export artifacts.
- Source site's WordPress version is compatible with the bundled Action Scheduler version.
- Operator has VIP CLI access to run import commands against the destination environment.
- VIP environments allow only one media import at a time; operator sequences multiple media archives if R10 splits apply.
- Serialized PHP data in the SQL dump is handled on import by `vip import sql --search-replace`, not by the plugin.

---

## Outstanding Questions

**Deferred to planning:**
- What is the minimum supported WordPress version?
- Which Action Scheduler version to bundle, and how should version conflicts be handled when a newer version is already active?
- How should partial artifacts be named and organized in the uploads directory across split archives (R10) and across multiple batched WXR files?
