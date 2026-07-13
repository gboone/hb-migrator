<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;

/**
 * U6 sync stage: rewrites URLs and remaps IDs for the rows a sync pass touched (supports
 * R4/R5/R6 correctness), without rescanning the whole destination database on every pass.
 * Registered under the 'search_replace' slot of the `hbm_sync_stages` filter (see
 * Plugin::register_action_hooks() and SyncDispatcher::get_stages()) — the fourth and final
 * stage in a sync pass, run after posts, media, and comments (see
 * SyncDispatcher::default_stages()' fixed iteration order).
 *
 * Reads the specific source post/attachment/comment IDs the three earlier stages touched
 * this same pass via their in-memory static handoffs — PostSyncStage::get_synced_post_ids(),
 * MediaSyncStage::get_synced_media_ids(), CommentSyncStage::get_synced_comment_ids() — the
 * same single-PHP-request handoff shape U4 established for parent_ids. Resolves each source
 * ID to its destination row ID via IdMap (an ID a stage fetched but never actually mapped —
 * e.g. a media item permanently skipped by the SSRF guard, or a comment skipped for an
 * unmapped parent — simply resolves to null and is left out of the scoped set; this is the
 * correct "nothing to do for this row yet" outcome, not an error).
 *
 * Delegates the actual replace/remap work to SearchReplace::replace_scoped() (U6), which
 * reuses safe_replace() and the same hbm_id_map-joined _thumbnail_id UPDATE...JOIN shape as
 * the whole-table mode, filtered to just these rows. That whole-table mode (run_phase(),
 * used at initial-migration finalize) is completely unchanged by this addition.
 *
 * Always returns false (no more work): the IDs it acts on are already bounded by the three
 * earlier stages' own per-pass row budgets (<=100 posts, <=50 media, <=100 comments), so a
 * single process() call here always finishes well within SearchReplace::TIME_LIMIT — no
 * checkpoint/continuation is needed, unlike the whole-table mode's phase+keyset dance.
 *
 * @see docs/plans/2026-07-13-002-feat-post-migration-content-sync-plan.md, "### U6. Scoped
 *      search-replace and ID remap for sync passes"
 */
class SyncSearchReplaceStage implements SyncStageInterface {

	public function process( int $site_job_id ): bool {
		$job = MigrationRegistry::get_site_job( $site_job_id );
		if ( ! $job || ! $job->dest_blog_id ) {
			return false;
		}

		$source_post_ids       = PostSyncStage::get_synced_post_ids( $site_job_id );
		$source_attachment_ids = MediaSyncStage::get_synced_media_ids( $site_job_id );
		$source_comment_ids    = CommentSyncStage::get_synced_comment_ids( $site_job_id );

		if ( empty( $source_post_ids ) && empty( $source_attachment_ids ) && empty( $source_comment_ids ) ) {
			// No earlier stage touched anything this pass — fast no-op, matching this plan's
			// "a scoped pass covering zero rows is a fast no-op, not an error" test scenario.
			// Skips even the site-job lookup's worth of extra IdMap queries below.
			return false;
		}

		$dest_post_ids = array_merge(
			self::map_ids( $site_job_id, 'post', $source_post_ids ),
			// Attachments are `wp_posts` rows too (post_type = 'attachment'), so their dest
			// IDs join the same posts/postmeta scoped WHERE IN() set as ordinary posts —
			// this is what lets a newly-synced post's _thumbnail_id (pointing at a
			// newly-synced attachment) get remapped within the same scoped pass.
			self::map_ids( $site_job_id, 'attachment', $source_attachment_ids )
		);
		$dest_comment_ids = self::map_ids( $site_job_id, 'comment', $source_comment_ids );

		if ( empty( $dest_post_ids ) && empty( $dest_comment_ids ) ) {
			// Everything this pass fetched was skipped upstream (e.g. every media item hit
			// the SSRF guard) — nothing has a destination row yet to rewrite.
			return false;
		}

		SearchReplace::replace_scoped( $site_job_id, $dest_post_ids, $dest_comment_ids );

		return false;
	}

	/**
	 * Resolves a list of source IDs to destination IDs via IdMap, silently dropping any
	 * source ID with no mapping yet (the correct "nothing to do for this row" outcome — see
	 * class docblock).
	 *
	 * @param int[] $source_ids
	 * @return int[]
	 */
	private static function map_ids( int $site_job_id, string $type, array $source_ids ): array {
		$dest_ids = [];
		foreach ( $source_ids as $source_id ) {
			$dest_id = IdMap::get( $site_job_id, $type, (int) $source_id );
			if ( null !== $dest_id ) {
				$dest_ids[] = $dest_id;
			}
		}
		return $dest_ids;
	}
}
