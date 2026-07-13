---
date: 2026-07-13
topic: post-migration-content-sync
---

# Post-Migration Content Sync

## Summary

HB Migrator gains an ongoing sync capability that keeps a destination site current with source during the post-migration QA window, without requiring a second full migration. Sync extends the existing migration pipeline — the same Reader/Importer/`IdMap` machinery, run again with a delta cursor — rather than a separate content-moving mechanism.

---

## Problem Frame

Once a site job reaches `complete`, there is no way today to pick up changes made on source afterward short of re-running the whole migration. A QA window can span hours to days, and source-side authors keep working during it — new posts, edited posts, new media, new comments. That content currently has no path to destination without a disruptive full re-migration or manual reconciliation.

This is anticipatory rather than incident-driven: no specific migration has hit this yet, but it's a predictable consequence of separating "migration technically finishes" from "customer QA sign-off actually happens." Separately, comments are not migrated by this plugin at all today, initial or ongoing — covering comments in sync means building comment migration from scratch, not just extending something that exists.

---

## Key Decisions

**Sync extends the existing pipeline; it does not introduce a second one.** A stage-by-stage check of the current migration pipeline found most of it already fits an incremental delta model: `IdMap` already re-applies to existing rows on retry, `TermImporter` already inserts terms only if missing by slug. Comments get built new but to that same Reader/Importer/`IdMap` pattern. Two stages don't fit the reuse model as-is and get different treatment rather than forced reuse — see below.

**Options are excluded from recurring sync.** `OptionImporter` wholesale-overwrites destination option values (with a security denylist). Re-applying it on every sync pass would clobber destination-side settings changes a QA reviewer made. Options remain a one-time initial-migration step, not part of sync.

**Search-replace / ID-remap scopes to the rows a sync pass touches.** The existing implementation rescans the entire destination posts/postmeta/options tables on every invocation — appropriate for a one-shot migration, not for something invoked on every cron tick during a multi-day QA window.

**Sync-pass failures are non-terminal.** Initial-migration failures mark the whole site job `failed` after retries exhaust, requiring manual "Retry Stage" intervention. A failed sync pass instead logs and waits for the next scheduled attempt — a transient blip during a days-long sync window shouldn't halt the whole job.

**Source always wins on conflicts.** A destination-side edit made during the sync window (e.g., a QA reviewer tweaks a migrated post directly on destination) is not protected — the next sync pass overwrites it with the source version. QA reviewers test against source, not by editing destination directly.

**Deletions are never propagated.** Destination only ever gains or corrects content during sync; it never loses content a source-side deletion removed.

**Dual trigger: webhook plus cron.** A source-side webhook nudges destination to sync near-immediately after a change. A cron/Action-Scheduler poll — Action Scheduler is already a bundled dependency but has no recurring-action usage anywhere in this plugin yet — runs as a safety net for missed or failed webhook calls. Both directions already hold each other's API credentials from initial migration setup, so no new trust bootstrapping is needed for either direction.

**Sync is a bounded, explicit window, not an always-on mode.** Sync only attaches to a site job after its initial migration reaches `complete`, via an explicit operator action, and continues until an explicit operator action ends it.

---

## Requirements

**Sync lifecycle**

- R1. An operator can enable sync on a site job only after its initial migration reaches `complete`.
- R2. An operator can end sync on a site job at any time, stopping both the webhook effect and the cron schedule for that job.
- R3. A sync-pass failure does not mark the site job `failed`; it retries on the next scheduled attempt instead.

**Content coverage**

- R4. New posts, and posts whose content changed since the last sync pass, are synced to destination.
- R5. New media, and media associated with a post that changed since the last sync pass, are synced to destination.
- R6. Comments are migrated to destination for the first time as part of this work: a one-time backfill of pre-existing comments, plus ongoing sync of new and edited comments during the sync window.
- R7. Deletions on source — of posts, media, or comments — are never propagated to destination.
- R8. Options are not re-applied during sync; they remain a one-time step from the initial migration.

**Trigger**

- R9. A source-side content event (new or edited post, new comment, new media) triggers a near-immediate sync attempt via a webhook call to destination.
- R10. A recurring scheduled sync pass runs independently of the webhook, catching any change a missed or failed webhook call didn't.

**Conflict handling**

- R11. When source content differs from what destination currently holds for a previously-synced item, the source version overwrites the destination version.

---

## Scope Boundaries

### Deferred for later

- Divergence detection or manual-reconciliation tooling for destination-side edits made during the sync window.
- Real-time or sub-minute sync guarantees — webhook is best-effort speed, cron is the only guaranteed floor.

### Outside this product's identity

- HB Migrator is not becoming a general-purpose live WordPress replication or mirroring tool. Sync exists specifically to bridge "migration technically complete" and "QA sign-off," bounded to an explicit window on a single already-migrated site job — not an ongoing multi-site sync product.

---

## Dependencies / Assumptions

- Assumes Action Scheduler's recurring-action capability is suitable for the cron safety net. It's bundled but has no existing recurring-action usage in this plugin to build from or conflict with.
- Assumes the source WordPress install stays live and reachable for the duration of the sync window — the same assumption the initial migration's REST-pull model already makes. This does not apply to the plugin's separate static SQL/WXR/media export migration path, where the source may not remain live after export; sync is scoped to the live REST-pull migration path only.

---

## Sources / Research

- `includes/destination/class-post-importer.php` — existing `IdMap` re-apply-to-existing-row path, reused for edit-sync.
- `includes/destination/class-term-importer.php` — existing insert-if-missing-by-slug pattern, naturally incremental.
- `includes/destination/class-option-importer.php` — wholesale option overwrite plus denylist, motivating the options exclusion from sync.
- `includes/destination/class-search-replace.php` — whole-table rescan per invocation, motivating the scoped-remap decision.
- `includes/class-pipeline-controller.php` — terminal `failed` status on retry exhaustion, motivating the non-terminal sync-failure decision.
- `includes/class-queue-table.php` — `hbm_migrations`/`hbm_site_jobs` schema; no reopen mechanism exists today.
