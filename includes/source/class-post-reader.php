<?php

namespace HBMigrator\Source;

class PostReader {

	public static function get_posts( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$blog_id  = (int) $request->get_param( 'blog_id' );
		$per_page = min( (int) ( $request->get_param( 'per_page' ) ?: 100 ), 500 );
		$last_id  = max( 0, (int) $request->get_param( 'last_id' ) );

		switch_to_blog( $blog_id );

		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->posts} WHERE ID > %d ORDER BY ID LIMIT %d",
			$last_id,
			$per_page
		) );

		if ( empty( $posts ) ) {
			restore_current_blog();
			return new \WP_REST_Response( [] );
		}

		$post_ids   = array_map( fn( $p ) => (int) $p->ID, $posts );
		$author_ids = array_values( array_unique( array_map( fn( $p ) => (int) $p->post_author, $posts ) ) );

		// Prime the user cache for all authors in one query — get_userdata() in the
		// loop below will hit cache instead of issuing N individual DB reads.
		if ( $author_ids ) {
			get_users( [ 'include' => $author_ids, 'number' => count( $author_ids ) ] );
		}

		// Batch-fetch all postmeta in one query using raw DB bytes so serialized values
		// arrive at the destination intact (get_post_meta() unserializes before returning,
		// which would lose the PHP serialization layer after JSON encoding/decoding).
		$all_meta = [];
		if ( $post_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			$meta_rows    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					...$post_ids
				),
				ARRAY_A
			);
			foreach ( $meta_rows ?? [] as $row ) {
				$all_meta[ (int) $row['post_id'] ][] = [ 'key' => $row['meta_key'], 'value' => $row['meta_value'] ];
			}
		}

		// Prime the term cache per post type — wp_get_object_terms() in the loop below
		// will read from cache instead of querying the DB once per post.
		$post_types = array_values( array_unique( array_map( fn( $p ) => $p->post_type, $posts ) ) );
		foreach ( $post_types as $post_type ) {
			$type_ids = array_values( array_map(
				fn( $p ) => (int) $p->ID,
				array_filter( $posts, fn( $p ) => $p->post_type === $post_type )
			) );
			update_object_term_cache( $type_ids, $post_type );
		}

		$data = [];
		foreach ( $posts as $post ) {
			$author_email = '';
			$author       = get_userdata( (int) $post->post_author ); // cache hit
			if ( $author ) {
				$author_email = $author->user_email;
			}

			$meta       = $all_meta[ (int) $post->ID ] ?? [];
			$terms_data = [];
			$taxonomies = get_object_taxonomies( $post->post_type );
			$post_terms = wp_get_object_terms( (int) $post->ID, $taxonomies ); // cache hit
			if ( ! is_wp_error( $post_terms ) ) {
				foreach ( $post_terms as $term ) {
					$terms_data[] = [ 'taxonomy' => $term->taxonomy, 'slug' => $term->slug ];
				}
			}

			$data[] = [
				'ID'                  => (int) $post->ID,
				'post_author_email'   => $author_email,
				'post_date'           => $post->post_date,
				'post_date_gmt'       => $post->post_date_gmt,
				'post_content'        => $post->post_content,
				'post_title'          => $post->post_title,
				'post_excerpt'        => $post->post_excerpt,
				'post_status'         => $post->post_status,
				'comment_status'      => $post->comment_status,
				'ping_status'         => $post->ping_status,
				'post_password'       => $post->post_password,
				'post_name'           => $post->post_name,
				'post_modified'       => $post->post_modified,
				'post_modified_gmt'   => $post->post_modified_gmt,
				'post_parent'         => (int) $post->post_parent,
				'menu_order'          => (int) $post->menu_order,
				'post_type'           => $post->post_type,
				'post_mime_type'      => $post->post_mime_type,
				'comment_count'       => (int) $post->comment_count,
				'meta'                => $meta,
				'terms'               => $terms_data,
			];
		}

		restore_current_blog();

		return new \WP_REST_Response( $data );
	}
}
