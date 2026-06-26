<?php
/**
 * Tests for PostImporter — comment_count preservation.
 *
 * Uses pre_http_request to mock SourceClient responses.
 */

use HBMigrator\Destination\PostImporter;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_PostImporter extends WP_UnitTestCase {

	private int $mid;
	private int $jid;

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();

		$this->mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'testkey', null );
		$this->jid = MigrationRegistry::create_site_job(
			$this->mid, 1, 'example.com', 'https://93.184.216.34', '', '/example.com/'
		);
		MigrationRegistry::update_migration_status( $this->mid, 'running' );
		MigrationRegistry::update_site_job( $this->jid, [ 'dest_blog_id' => get_current_blog_id() ] );
	}

	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'pre_http_request' );
	}

	private function mock_posts_response( array $posts ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $posts ) {
			if ( false !== strpos( $url, '/posts' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( $posts ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );
	}

	private function make_source_post( array $overrides = [] ): array {
		return array_merge( [
			'ID'                => 1,
			'post_author_email' => '',
			'post_date'         => '2024-01-01 00:00:00',
			'post_date_gmt'     => '2024-01-01 00:00:00',
			'post_content'      => 'Hello world.',
			'post_title'        => 'Test Post',
			'post_excerpt'      => '',
			'post_status'       => 'publish',
			'comment_status'    => 'open',
			'ping_status'       => 'open',
			'post_password'     => '',
			'post_name'         => 'test-post',
			'post_modified'     => '2024-01-01 00:00:00',
			'post_modified_gmt' => '2024-01-01 00:00:00',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_type'         => 'post',
			'post_mime_type'    => '',
			'comment_count'     => 0,
			'meta'              => [],
			'terms'             => [],
		], $overrides );
	}

	public function test_comment_count_is_written_to_destination_post(): void {
		$source_post = $this->make_source_post( [ 'comment_count' => 7 ] );
		$this->mock_posts_response( [ $source_post ] );

		PostImporter::process( $this->jid, 0, 0 );

		// Find the post that was just inserted.
		$posts = get_posts( [
			'post_type'   => 'post',
			'post_status' => 'any',
			'numberposts' => 1,
			'orderby'     => 'date',
			'order'       => 'DESC',
		] );

		$this->assertNotEmpty( $posts, 'PostImporter did not create a destination post.' );
		$this->assertSame( 7, (int) $posts[0]->comment_count );
	}

	public function test_zero_comment_count_is_preserved(): void {
		$source_post = $this->make_source_post( [ 'comment_count' => 0 ] );
		$this->mock_posts_response( [ $source_post ] );

		PostImporter::process( $this->jid, 0, 0 );

		$posts = get_posts( [
			'post_type'   => 'post',
			'post_status' => 'any',
			'numberposts' => 1,
			'orderby'     => 'date',
			'order'       => 'DESC',
		] );

		$this->assertNotEmpty( $posts );
		$this->assertSame( 0, (int) $posts[0]->comment_count );
	}

	public function test_post_meta_is_written(): void {
		$source_post = $this->make_source_post( [
			'meta' => [
				[ 'key' => '_custom_field', 'value' => 'custom-value' ],
			],
		] );
		$this->mock_posts_response( [ $source_post ] );

		PostImporter::process( $this->jid, 0, 0 );

		$posts = get_posts( [ 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => 1 ] );
		$this->assertNotEmpty( $posts );
		$this->assertSame( 'custom-value', get_post_meta( $posts[0]->ID, '_custom_field', true ) );
	}

	public function test_attachment_post_type_is_skipped(): void {
		$attachment = $this->make_source_post( [
			'ID'            => 999,
			'post_type'     => 'attachment',
			'post_mime_type' => 'image/jpeg',
			'post_name'     => 'photo-jpg',
		] );
		$this->mock_posts_response( [ $attachment ] );

		PostImporter::process( $this->jid, 0, 0 );

		// No attachment post should have been created.
		$created = get_posts( [
			'post_type'   => 'attachment',
			'post_status' => 'any',
			'numberposts' => -1,
		] );
		$this->assertEmpty( $created, 'PostImporter must not create attachment posts.' );
	}

	public function test_attachment_skipped_and_regular_post_imported_in_mixed_batch(): void {
		$attachment = $this->make_source_post( [
			'ID'        => 801,
			'post_type' => 'attachment',
			'post_name' => 'image-jpg',
		] );
		$post = $this->make_source_post( [
			'ID'        => 802,
			'post_type' => 'post',
			'post_name' => 'my-article',
		] );
		$this->mock_posts_response( [ $attachment, $post ] );

		PostImporter::process( $this->jid, 0, 0 );

		// The attachment must be absent.
		$att_posts = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1 ] );
		$this->assertEmpty( $att_posts );

		// The regular post must be present.
		$reg_posts = get_posts( [ 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1 ] );
		$this->assertCount( 1, $reg_posts );
		$this->assertSame( 'my-article', $reg_posts[0]->post_name );
	}

	public function test_attachment_source_id_not_in_idmap(): void {
		$attachment = $this->make_source_post( [
			'ID'        => 501,
			'post_type' => 'attachment',
		] );
		$this->mock_posts_response( [ $attachment ] );

		PostImporter::process( $this->jid, 0, 0 );

		$this->assertNull( \HBMigrator\IdMap::get( $this->jid, 'post', 501 ) );
	}
}
