<?php

namespace HBMigrator\Source;

class MediaReader {

	/**
	 * Minimum safety overlap (seconds) subtracted from a delta-sync cursor before querying,
	 * regardless of the configured cron interval — mirrors PostReader::MIN_OVERLAP_SECONDS.
	 */
	private const MIN_OVERLAP_SECONDS = 60;

	/**
	 * Default sync-pass poll cadence (seconds), used only as the `hbm_sync_interval` filter's
	 * fallback — the same filter name, and same fallback value, as PostReader's U3 delta-cursor
	 * mode (see class-post-reader.php's docblock for the full coordination-point rationale).
	 */
	private const DEFAULT_SYNC_CRON_INTERVAL_SECONDS = 15 * MINUTE_IN_SECONDS;

	public static function get_media( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$blog_id      = (int) $request->get_param( 'blog_id' );
		$per_page     = min( (int) ( $request->get_param( 'per_page' ) ?: 50 ), 200 );
		$offset       = max( 0, (int) $request->get_param( 'offset' ) );
		$attached_only = ! empty( $request->get_param( 'attached_only' ) );

		// When specific IDs are requested, fetch only those attachments. Unchanged from its
		// existing retry-after-failure purpose — always takes precedence over both the
		// offset-paginated initial-migration mode and the new delta-cursor mode below.
		$raw_ids = $request->get_param( 'ids' );
		$ids     = [];
		if ( ! empty( $raw_ids ) ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) $raw_ids ) ) );
			$ids = array_slice( $ids, 0, 200 );
		}

		// U4 delta-cursor mode (sync passes): mirrors PostReader's modified_since + last_id
		// keyset shape. Only consulted when `ids` is empty (see above). `parent_ids` is an
		// additive third condition alongside the ID and post_modified branches PostReader
		// already has — attachments whose parent post was touched by PostSyncStage this same
		// pass (e.g. a featured image swapped on an edited post) are included even when the
		// attachment row itself is unchanged. See MediaSyncStage, which is the only caller
		// that populates parent_ids (sourced from PostSyncStage::get_synced_post_ids()).
		$modified_since = $request->get_param( 'modified_since' );
		$last_id        = max( 0, (int) $request->get_param( 'last_id' ) );
		$raw_parent_ids = $request->get_param( 'parent_ids' );
		$parent_ids     = [];
		if ( ! empty( $raw_parent_ids ) ) {
			$parent_ids = array_values( array_filter( array_map( 'absint', (array) $raw_parent_ids ) ) );
		}
		$since_ts = ( empty( $ids ) && $modified_since ) ? strtotime( (string) $modified_since ) : false;

		switch_to_blog( $blog_id );

		$parent_filter = null;

		if ( ! empty( $ids ) ) {
			// IDs-based retry pass: always fetch the specific IDs regardless of scope.
			$attachments = get_posts( [
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'post__in'    => $ids,
				'numberposts' => count( $ids ),
			] );
		} elseif ( false !== $since_ts ) {
			// Delta-cursor mode: a genuinely new attachment is always caught via
			// ID > last_id; an edit to an already-synced attachment (e.g. alt text) is
			// caught via post_modified clearing the overlap-adjusted floor; an attachment
			// whose parent post was just synced is caught via post_parent IN (parent_ids)
			// even with no change of its own. Overlap scales with the poll cadence via the
			// same `hbm_sync_interval` filter PostReader reads — see PostReader's docblock.
			$interval = (int) apply_filters( 'hbm_sync_interval', self::DEFAULT_SYNC_CRON_INTERVAL_SECONDS );
			$overlap  = max( self::MIN_OVERLAP_SECONDS, $interval );
			$floor    = gmdate( 'Y-m-d H:i:s', $since_ts - $overlap );

			$where   = "post_type = 'attachment' AND ( ID > %d OR post_modified > %s";
			$params  = [ $last_id, $floor ];

			if ( ! empty( $parent_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) );
				$where       .= " OR post_parent IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$params       = array_merge( $params, $parent_ids );
			}

			$where .= ' )';
			$params[] = $per_page;

			$attachments = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT * FROM {$wpdb->posts} WHERE {$where} ORDER BY ID LIMIT %d",
				...$params
			) );
		} else {
			// Initial-migration mode (unchanged): offset pagination, optionally scoped to
			// attached-only via posts_where.
			$query_args = [
				'post_type'     => 'attachment',
				'post_status'   => 'any',
				'orderby'       => 'ID',
				'order'         => 'ASC',
				'posts_per_page' => $per_page,
				'offset'        => $offset,
			];

			if ( $attached_only ) {
				// get_posts() defaults suppress_filters to true, which would otherwise make
				// this posts_where addition silently do nothing.
				$query_args['suppress_filters'] = false;
				$parent_filter = static function ( string $where ) use ( $wpdb ): string {
					return $where . " AND {$wpdb->posts}.post_parent > 0";
				};
				add_filter( 'posts_where', $parent_filter );
			}

			$attachments = get_posts( $query_args );
		}

		if ( $parent_filter ) {
			remove_filter( 'posts_where', $parent_filter );
		}

		$data = [];
		foreach ( $attachments as $att ) {
			$file_url = wp_get_attachment_url( $att->ID );
			$data[]   = [
				'source_attachment_id' => (int) $att->ID,
				'post_title'           => $att->post_title,
				'post_date'            => $att->post_date,
				'post_date_gmt'        => $att->post_date_gmt,
				'post_modified'        => $att->post_modified,
				'post_mime_type'       => $att->post_mime_type,
				'post_parent_source_id' => (int) $att->post_parent,
				'post_name'            => $att->post_name,
				'alt_text'             => get_post_meta( $att->ID, '_wp_attachment_image_alt', true ),
				'caption'              => $att->post_excerpt,
				'description'          => $att->post_content,
				'file_url'             => $file_url ?: '',
			];
		}

		restore_current_blog();

		return new \WP_REST_Response( $data );
	}
}
