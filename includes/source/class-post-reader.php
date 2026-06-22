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

		$data = [];
		foreach ( $posts as $post ) {
			$author_email = '';
			$author       = get_userdata( (int) $post->post_author );
			if ( $author ) {
				$author_email = $author->user_email;
			}

			$meta  = [];
			$raw   = get_post_meta( (int) $post->ID );
			foreach ( $raw as $key => $values ) {
				foreach ( $values as $value ) {
					$meta[] = [ 'key' => $key, 'value' => $value ];
				}
			}

			$terms_data  = [];
			$taxonomies  = get_object_taxonomies( $post->post_type );
			$post_terms  = wp_get_object_terms( (int) $post->ID, $taxonomies );
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
