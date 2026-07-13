<?php

namespace HBMigrator\Source;

/**
 * U5 source-side reader for comment migration (R6). Follows TermReader's shape (per-blog
 * fetch, pagination, flat field mapping) with a last_id keyset cursor like PostReader's
 * original (pre-delta-cursor) design.
 *
 * Unlike PostReader/MediaReader, this reader has no modified_since delta mode: wp_comments has
 * no modified-timestamp column, so only new-comment detection is possible via a cursor —
 * comment edits and moderation-status changes to already-synced comments are webhook-only (see
 * docs/plans/2026-07-13-002-feat-post-migration-content-sync-plan.md, "Key Technical
 * Decisions", "Comments are new but follow the same Reader/Importer/IdMap shape" and "Comment
 * edits and moderation-status changes are webhook-only").
 *
 * Deliberately does NOT filter on comment_approved — spam/trash comments are included so the
 * one-time backfill (and ongoing sync) is full-fidelity with source; WordPress core's standard
 * display gating (spam/trash isn't rendered publicly) is the safeguard, not a query-side
 * filter. comment_type is returned as stored so pingback/trackback rows are identifiable
 * rather than silently mistyped as ordinary comments.
 *
 * @see docs/plans/2026-07-13-002-feat-post-migration-content-sync-plan.md, "### U5. Comment
 *      migration"
 */
class CommentReader {

	public static function get_comments( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$blog_id  = (int) $request->get_param( 'blog_id' );
		$per_page = min( (int) ( $request->get_param( 'per_page' ) ?: 100 ), 500 );
		$last_id  = max( 0, (int) $request->get_param( 'last_id' ) );

		switch_to_blog( $blog_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- $wpdb->comments is a hardcoded table property, values below are all %d/%s prepared.
		$comments = $wpdb->get_results( $wpdb->prepare(
			"SELECT comment_ID, comment_post_ID, comment_parent, comment_type, user_id,
			        comment_approved, comment_content, comment_date, comment_date_gmt,
			        comment_author, comment_author_email, comment_author_url
			   FROM {$wpdb->comments}
			  WHERE comment_ID > %d
			  ORDER BY comment_ID ASC
			  LIMIT %d",
			$last_id,
			$per_page
		) );

		$data = [];
		foreach ( $comments ?? [] as $c ) {
			$data[] = [
				'comment_ID'           => (int) $c->comment_ID,
				'comment_post_ID'      => (int) $c->comment_post_ID,
				'comment_parent'       => (int) $c->comment_parent,
				'comment_type'         => $c->comment_type,
				'user_id'              => (int) $c->user_id,
				'comment_approved'     => $c->comment_approved,
				'comment_content'      => $c->comment_content,
				'comment_date'         => $c->comment_date,
				'comment_date_gmt'     => $c->comment_date_gmt,
				'comment_author'       => $c->comment_author,
				'comment_author_email' => $c->comment_author_email,
				'comment_author_url'   => $c->comment_author_url,
			];
		}

		restore_current_blog();

		return new \WP_REST_Response( $data );
	}
}
