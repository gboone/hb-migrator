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
	 * @param int[] $force_top_level_ids Source comment_ID values (usually zero or one) for
	 *                        which the comment_parent -> 'comment' IdMap lookup below is
	 *                        skipped entirely and the comment is inserted/updated with
	 *                        comment_parent = 0, regardless of the source row's own
	 *                        comment_parent value. Set only by CommentSyncStage, and only
	 *                        after a comment has been the cursor's blocking item for
	 *                        CommentSyncStage::MAX_STALL_PASSES consecutive passes without its
	 *                        parent ever resolving (e.g. the parent comment was deleted on
	 *                        source — WordPress core never cascades that deletion to replies,
	 *                        so the reply's comment_parent can never resolve via IdMap on its
	 *                        own). Does not affect the comment_post_ID resolution above it: a
	 *                        comment whose post is still unmapped is skipped exactly as before
	 *                        even when its ID appears here — forcing only ever waives the
	 *                        parent check, never fabricates a destination post.
	 * @return int[] Source comment_IDs that were fetched but did not end up durably synced this
	 *               call: an unresolved comment_post_ID/comment_parent reference (see the
	 *               resolution order above), OR a wp_insert_comment()/wp_update_comment()
	 *               write failure (code-review addition — previously a write failure was only
	 *               `continue`d past with no signal to the caller, so CommentSyncStage's
	 *               cursor-advance walk had no way to distinguish "content actually updated
	 *               this pass" from "write silently failed, old content still stands" for an
	 *               already-IdMap-mapped comment; both now count as "not durably synced" so the
	 *               cursor doesn't advance past a comment whose update just failed).
	 */
	public static function process( int $site_job_id, array $comments, array $force_top_level_ids = [] ): array {
		global $wpdb;

		$failed_ids = [];

		if ( empty( $comments ) ) {
			return $failed_ids;
		}

		$job = MigrationRegistry::get_site_job( $site_job_id );
		if ( ! $job || ! $job->dest_blog_id ) {
			// Job/migration missing is not a per-comment failure — nothing was attempted.
			return $failed_ids;
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
					$failed_ids[] = $source_id;
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
						$failed_ids[] = $source_id;
						continue;
					}
				}

				$dest_user_id = 0;
				if ( (int) $c['user_id'] > 0 ) {
					$dest_user_id = IdMap::get( IdMap::NETWORK, 'user', (int) $c['user_id'] ) ?? 0;
				}

				// Sanitize all free-text/user-controlled comment fields before they ever reach
				// wp_insert_comment()/wp_update_comment(). Unlike PostImporter's post_author
				// (a vetted source-site Editor/Admin account, hence that importer's deliberate
				// kses_remove_filters()/kses_init_filters() bracket to relax sanitization),
				// comment_author/_email/_url/_content can originate from anonymous,
				// unauthenticated public visitors on the source site — including
				// never-publicly-rendered but still-stored spam/pending payloads (CommentReader
				// deliberately does not filter by comment_approved). wp_insert_comment() and
				// wp_update_comment() are the programmatic API and do NOT run the sanitization
				// that WordPress core's normal comment-submission path (wp_filter_comment() and
				// its pre_comment_content/pre_comment_author_name/etc. filters, invoked by
				// wp_new_comment()) applies to comments submitted via the comment form or REST
				// API. Without this, a stored-XSS payload sitting in source's wp_comments table
				// would migrate to destination byte-for-byte and could execute if the comment is
				// later approved/rendered there. wp_kses_post() mirrors the safe-HTML subset
				// core allows in comment content post-moderation while stripping <script>/
				// on-event-handler vectors; sanitize_text_field()/sanitize_email()/
				// esc_url_raw() mirror the equivalent core sanitizers for the author triplet
				// (esc_url_raw() routes through wp_kses_bad_protocol(), which strips
				// non-whitelisted schemes such as javascript:).
				$comment_data = [
					'comment_post_ID'      => $dest_post_id,
					'comment_author'       => sanitize_text_field( (string) $c['comment_author'] ),
					'comment_author_email' => sanitize_email( (string) $c['comment_author_email'] ),
					'comment_author_url'   => esc_url_raw( (string) $c['comment_author_url'] ),
					'comment_content'      => wp_kses_post( (string) $c['comment_content'] ),
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
						// wp_update_post() failure. Code-review addition: report this as a
						// failure to the caller even though the comment IS already IdMap-mapped
						// (from its original successful insert) — without this, CommentSyncStage
						// had no way to tell "content updated this pass" from "update silently
						// failed", and would advance its cursor past this comment regardless.
						$failed_ids[] = $source_id;
						continue;
					}
				} else {
					$dest_id = wp_insert_comment( wp_slash( $comment_data ) );
					if ( ! $dest_id ) {
						$failed_ids[] = $source_id;
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

		return $failed_ids;
	}
}
