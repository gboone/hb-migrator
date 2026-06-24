<?php

namespace HBMigrator\Source;

class MediaReader {

	public static function get_media( \WP_REST_Request $request ): \WP_REST_Response {
		$blog_id  = (int) $request->get_param( 'blog_id' );
		$per_page = min( (int) ( $request->get_param( 'per_page' ) ?: 50 ), 200 );
		$offset   = max( 0, (int) $request->get_param( 'offset' ) );

		// When specific IDs are requested, fetch only those attachments.
		$raw_ids = $request->get_param( 'ids' );
		$ids     = [];
		if ( ! empty( $raw_ids ) ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) $raw_ids ) ) );
			$ids = array_slice( $ids, 0, 200 );
		}

		switch_to_blog( $blog_id );

		$query_args = [
			'post_type'   => 'attachment',
			'post_status' => 'any',
			'orderby'     => 'ID',
			'order'       => 'ASC',
		];

		if ( ! empty( $ids ) ) {
			$query_args['post__in']      = $ids;
			$query_args['numberposts']   = count( $ids );
		} else {
			$query_args['posts_per_page'] = $per_page;
			$query_args['offset']         = $offset;
		}

		$attachments = get_posts( $query_args );

		$data = [];
		foreach ( $attachments as $att ) {
			$file_url = wp_get_attachment_url( $att->ID );
			$data[]   = [
				'source_attachment_id' => (int) $att->ID,
				'post_title'           => $att->post_title,
				'post_date'            => $att->post_date,
				'post_date_gmt'        => $att->post_date_gmt,
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
