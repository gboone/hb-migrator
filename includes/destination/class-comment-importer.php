<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;

/**
 * U5 destination-side importer for comment migration (R6). Called by CommentSyncStage with a
 * batch already fetched from CommentReader — mirrors PostImporter::import_batch()'s shape and
 * is the same Reader/Importer/IdMap pipeline every other content type in this plugin uses.
 *
 * Resolution order per comment, matching the plan's pseudocode exactly (see
 * docs/plans/2026-07-13-002-feat-post-migration-content-sync-plan.md, "### U5. Comment
 * migration", "Technical design"):
 *   1. comment_post_ID -> post IdMap. Unresolved: skip, retry next pass.
 *   2. comment_parent (if non-zero) -> a NEW 'comment' IdMap object_type. Unresolved: skip,
 *      retry next pass. comment_parent has no WordPress-core guarantee of pointing at a lower
 *      comment_ID — verified against wp_insert_comment(), which performs no such validation —
 *      so comment_ID ascending order is never assumed as a substitute for this check.
 *   3. user_id (if non-zero) -> the existing 'user' IdMap object_type (IdMap::NETWORK scope,
 *      same as UserImporter/TermImporter's role-assignment lookup). Falls back to 0/anonymous
 *      when unmapped — this is an expected outcome, not an error.
 *   4. THEN, and only then, IdMap::get(site_job_id, 'comment', comment_ID) decides insert vs.
 *      update — a comment already inserted and mapped on a prior pass that then failed at a
 *      later stage (e.g. scoped search-replace, U6) is updated in place on retry, not
 *      duplicated. Mirrors PostImporter's existing-row branch.
 */
class CommentImporter {

	/**
	 * @param int   $site_job_id
	 * @param array $comments Already-fetched from CommentReader::get_comments() (or a stub in
	 *                        tests) — see class docblock for the resolve-then-upsert order.
	 */
	public static function process( int $site_job_id, array $comments ): void {
		global $wpdb;

		if ( empty( $comments ) ) {
			return;
		}

		$job = MigrationRegistry::get_site_job( $site_job_id );
		if ( ! $job || ! $job->dest_blog_id ) {
			return;
		}

		// Comment/post/user IdMap lookups below are keyed by site_job_id only (not by blog),
		// so they work correctly regardless of which blog is currently active — but
		// wp_insert_comment()/wp_update_comment() and the defensive $wpdb->update() operate
		// on whichever blog's tables are currently switched to, so this bracket is required
		// for multisite correctness, mirroring PostImporter::import_batch()'s switch_to_blog()
		// bracketing.
		switch_to_blog( (int) $job->dest_blog_id );

		try {
			foreach ( $comments as $c ) {
				$source_id = (int) $c['comment_ID'];

				$dest_post_id = IdMap::get( $site_job_id, 'post', (int) $c['comment_post_ID'] );
				if ( null === $dest_post_id ) {
					// The comment's post hasn't synced to destination yet — skip and retry
					// next pass rather than drop or attach to nothing (R6, plan: "a comment
					// whose comment_post_ID or comment_parent has no destination mapping yet
					// is skipped this pass and retried on the next one, not dropped").
					continue;
				}

				$dest_parent_id = 0;
				if ( (int) $c['comment_parent'] > 0 ) {
					$dest_parent_id = IdMap::get( $site_job_id, 'comment', (int) $c['comment_parent'] );
					if ( null === $dest_parent_id ) {
						// Parent comment not yet synced (or never will be, e.g. filtered on
						// source) — skip and retry next pass. Do NOT assume comment_ID
						// ordering: a reply can legitimately have a comment_parent higher
						// than its own comment_ID (REST-API-created or WXR-imported comment
						// sets), and wp_insert_comment() performs no parent-existence
						// validation, so this check is a required defense, not
						// defense-in-depth.
						continue;
					}
				}

				$dest_user_id = 0;
				if ( (int) $c['user_id'] > 0 ) {
					$dest_user_id = IdMap::get( IdMap::NETWORK, 'user', (int) $c['user_id'] ) ?? 0;
				}

				$comment_data = [
					'comment_post_ID'      => $dest_post_id,
					'comment_author'       => (string) $c['comment_author'],
					'comment_author_email' => (string) $c['comment_author_email'],
					'comment_author_url'   => (string) $c['comment_author_url'],
					'comment_content'      => (string) $c['comment_content'],
					'comment_type'         => (string) $c['comment_type'],
					'comment_parent'       => $dest_parent_id,
					'user_id'              => $dest_user_id,
					'comment_date'         => $c['comment_date'],
					'comment_date_gmt'     => $c['comment_date_gmt'],
					'comment_approved'     => $c['comment_approved'],
				];

				$existing_dest_id = IdMap::get( $site_job_id, 'comment', $source_id );
				if ( null !== $existing_dest_id ) {
					// Already inserted and mapped on a prior pass that then failed at a
					// later stage — update in place, do not duplicate.
					$dest_id       = $existing_dest_id;
					$update_result = wp_update_comment( wp_slash( array_merge( $comment_data, [ 'comment_ID' => $dest_id ] ) ), true );
					if ( is_wp_error( $update_result ) || ! $update_result ) {
						// Leave the existing destination row untouched rather than partially
						// apply — mirrors PostImporter's existing-row branch on
						// wp_update_post() failure.
						continue;
					}
				} else {
					$dest_id = wp_insert_comment( wp_slash( $comment_data ) );
					if ( ! $dest_id ) {
						continue;
					}
					IdMap::set( $site_job_id, 'comment', $source_id, (int) $dest_id );
				}

				// wp_update_comment() unconditionally recomputes comment_date_gmt from
				// comment_date via get_gmt_from_date() rather than preserving the value
				// passed in — confirmed against WordPress core (wp-includes/comment.php) —
				// which would silently drift the destination timestamp away from source's
				// exact GMT value on every retry/edit-sync pass. Follow PostImporter's
				// $wpdb->update() precedent (used there for comment_count) to write
				// comment_date_gmt and comment_approved directly after either branch — a
				// no-op on the insert path (wp_insert_comment() already preserves both
				// fields as given) and the fix for the update path. Preserves moderation
				// status (comment_approved), including spam/trash, exactly as it was on
				// source.
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->comments,
					[
						'comment_date_gmt' => $comment_data['comment_date_gmt'],
						'comment_approved' => $comment_data['comment_approved'],
					],
					[ 'comment_ID' => $dest_id ]
				);
			}
		} finally {
			restore_current_blog();
		}
	}
}
