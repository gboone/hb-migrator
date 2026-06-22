<?php

namespace HBMigrator\Source;

class TermReader {

	public static function get_terms( \WP_REST_Request $request ): \WP_REST_Response {
		$blog_id  = (int) $request->get_param( 'blog_id' );
		$per_page = min( (int) ( $request->get_param( 'per_page' ) ?: 100 ), 500 );
		$offset   = max( 0, (int) $request->get_param( 'offset' ) );

		switch_to_blog( $blog_id );

		$taxonomies = get_taxonomies();
		$terms      = get_terms( [
			'taxonomy'   => array_values( $taxonomies ),
			'hide_empty' => false,
			'number'     => $per_page,
			'offset'     => $offset,
			'orderby'    => 'term_id',
			'order'      => 'ASC',
		] );

		$data = [];
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$data[] = [
					'term_id'     => (int) $term->term_id,
					'name'        => $term->name,
					'slug'        => $term->slug,
					'taxonomy'    => $term->taxonomy,
					'description' => $term->description,
					'parent'      => (int) $term->parent,
				];
			}
		}

		restore_current_blog();

		return new \WP_REST_Response( $data );
	}
}
