<?php
/**
 * Tests for Source\SyncWebhook — U8's source-side content-event hooks, debounce, the
 * actual webhook HTTP call, and the receive_token() endpoint that closes the token-delivery
 * gap documented in that class's docblock.
 *
 * Ordering note: Plugin::get_instance() runs on 'plugins_loaded' (hb-migrator.php), which
 * calls Source\SyncWebhook::init() — so save_post/wp_insert_comment/etc. are ALREADY wired
 * to the real handlers for the whole test run, not just when a test calls them directly.
 * Every test below therefore creates any post/comment fixture *before* calling
 * configure_sync() below, so a factory-triggered real hook firing during fixture setup is
 * guaranteed to see "sync not configured yet" and no-op, exactly like a real install before
 * Enable Sync was ever clicked. Only the explicit calls made after configure_sync() are what
 * each assertion is about — this makes each test correct whether or not the real global
 * hook wiring is present in a given test run.
 */

use HBMigrator\Source\SyncWebhook;

class Test_SyncWebhook extends WP_UnitTestCase {

	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'pre_http_request' );
		delete_site_option( 'hbm_dest_url' );
		delete_site_option( 'hbm_dest_key' );
		delete_blog_option( get_current_blog_id(), 'hbm_sync_config' );
		self::clear_scheduled_send_hook();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function configure_sync( int $site_job_id = 42, string $token = 'test-webhook-token' ): void {
		update_blog_option( get_current_blog_id(), 'hbm_sync_config', [
			'site_job_id' => $site_job_id,
			'token'       => $token,
		] );
	}

	private static function clear_scheduled_send_hook(): void {
		$args = [ 'blog_id' => get_current_blog_id() ];
		$ts   = wp_next_scheduled( SyncWebhook::SEND_HOOK, $args );
		if ( $ts ) {
			wp_unschedule_event( $ts, SyncWebhook::SEND_HOOK, $args );
		}
	}

	private function scheduled_send_count(): int {
		$crons = _get_cron_array();
		$count = 0;
		foreach ( $crons ?: [] as $events ) {
			if ( ! isset( $events[ SyncWebhook::SEND_HOOK ] ) ) {
				continue;
			}
			$count += count( $events[ SyncWebhook::SEND_HOOK ] );
		}
		return $count;
	}

	// -------------------------------------------------------------------------
	// Sync not configured — every hook is a safe no-op (hooks are registered
	// unconditionally on every install; see Plugin::setup()).
	// -------------------------------------------------------------------------

	public function test_save_post_without_sync_configured_schedules_nothing(): void {
		$post_id = self::factory()->post->create();

		SyncWebhook::on_save_post( $post_id );

		$this->assertSame( 0, $this->scheduled_send_count() );
	}

	// -------------------------------------------------------------------------
	// save_post: genuine save schedules a debounced call; autosave/revision never do.
	// -------------------------------------------------------------------------

	public function test_genuine_save_post_schedules_webhook_within_debounce_window(): void {
		$post_id = self::factory()->post->create();
		$this->configure_sync();

		SyncWebhook::on_save_post( $post_id );

		$args = [ 'blog_id' => get_current_blog_id() ];
		$ts   = wp_next_scheduled( SyncWebhook::SEND_HOOK, $args );

		$this->assertNotFalse( $ts, 'A genuine save must schedule the debounced webhook call.' );
		$this->assertGreaterThan( time(), $ts, 'The scheduled call must be in the future, not fired inline.' );
		$this->assertLessThanOrEqual( time() + 10, $ts, 'The debounce window must be a few seconds, not a long delay.' );
	}

	public function test_autosave_triggered_save_post_does_not_schedule_a_webhook_call(): void {
		$post_id = self::factory()->post->create();
		$this->configure_sync();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		// wp_create_post_autosave() is WordPress core's own mechanism for producing an
		// autosave revision — more reliable than hand-constructing a post_name convention
		// that could drift from core's exact format across WP versions.
		$autosave_id = wp_create_post_autosave( [
			'post_ID'      => $post_id,
			'post_type'    => 'post',
			'post_title'   => 'Autosaved title',
			'post_content' => 'Autosaved content',
		] );

		$this->assertIsInt( $autosave_id, 'Precondition: wp_create_post_autosave() must succeed for this test to be meaningful.' );
		$this->assertNotFalse( wp_is_post_autosave( $autosave_id ), 'Precondition: WP core must actually recognize this row as an autosave.' );

		SyncWebhook::on_save_post( $autosave_id );

		$this->assertSame( 0, $this->scheduled_send_count(), 'An autosave-triggered save_post must not schedule a webhook call at all.' );
	}

	public function test_revision_save_does_not_schedule_a_webhook_call(): void {
		$post_id = self::factory()->post->create();
		$this->configure_sync();

		// A post revision row is, by WP core definition, post_type = 'revision' —
		// wp_is_post_revision() checks exactly that.
		$revision_id = self::factory()->post->create( [
			'post_type'   => 'revision',
			'post_status' => 'inherit',
			'post_parent' => $post_id,
		] );

		SyncWebhook::on_save_post( $revision_id );

		$this->assertSame( 0, $this->scheduled_send_count(), 'A revision-save-triggered save_post must not schedule a webhook call.' );
	}

	// -------------------------------------------------------------------------
	// Coalescing — multiple rapid genuine-save firings within one request.
	// -------------------------------------------------------------------------

	public function test_multiple_rapid_save_post_firings_coalesce_into_one_scheduled_call(): void {
		$post_id = self::factory()->post->create();
		$this->configure_sync();

		// Simulates the revision-insert firing plus the actual post-update firing that
		// happen within the same PHP request for a single genuine save.
		SyncWebhook::on_save_post( $post_id );
		SyncWebhook::on_save_post( $post_id );
		SyncWebhook::on_save_post( $post_id );

		$this->assertSame( 1, $this->scheduled_send_count(), 'Rapid genuine-save firings must coalesce into a single scheduled call, not stack.' );
	}

	// -------------------------------------------------------------------------
	// Comment hooks.
	// -------------------------------------------------------------------------

	public function test_wp_insert_comment_schedules_webhook_call(): void {
		$this->configure_sync();

		SyncWebhook::on_new_comment( 123 );

		$this->assertSame( 1, $this->scheduled_send_count() );
	}

	public function test_edit_comment_schedules_webhook_call(): void {
		$this->configure_sync();

		SyncWebhook::on_comment_changed( 123 );

		$this->assertSame( 1, $this->scheduled_send_count() );
	}

	public function test_transition_comment_status_schedules_webhook_call_on_real_change(): void {
		$comment_id = self::factory()->comment->create();
		$comment    = get_comment( $comment_id );
		$this->configure_sync();

		SyncWebhook::on_transition_comment_status( 'approved', 'hold', $comment );

		$this->assertSame( 1, $this->scheduled_send_count(), 'A real moderation-status change must schedule a webhook call — this is the ONLY path (no cron fallback) for an already-synced comment\'s status change to reach destination.' );
	}

	public function test_transition_comment_status_no_op_when_status_unchanged(): void {
		$comment_id = self::factory()->comment->create();
		$comment    = get_comment( $comment_id );
		$this->configure_sync();

		SyncWebhook::on_transition_comment_status( 'approved', 'approved', $comment );

		$this->assertSame( 0, $this->scheduled_send_count(), 'transition_comment_status fires even without a real change — must be a no-op.' );
	}

	// -------------------------------------------------------------------------
	// Media hook.
	// -------------------------------------------------------------------------

	public function test_add_attachment_schedules_webhook_call(): void {
		$this->configure_sync();

		SyncWebhook::on_add_attachment( 456 );

		$this->assertSame( 1, $this->scheduled_send_count() );
	}

	// -------------------------------------------------------------------------
	// send_webhook() — the actual HTTP call, fired from the debounce callback (not inline
	// in the triggering hook).
	// -------------------------------------------------------------------------

	public function test_send_webhook_posts_token_to_destination_sync_notify_endpoint(): void {
		update_site_option( 'hbm_dest_url', 'https://destination.example.com' );
		update_site_option( 'hbm_dest_key', 'dest-api-key' );
		$this->configure_sync( 42, 'the-sync-token' );

		$captured_url  = null;
		$captured_args = null;
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url, &$captured_args ) {
			$captured_url  = $url;
			$captured_args = $args;
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'body'     => wp_json_encode( [ 'status' => 'queued' ] ),
				'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
				'cookies'  => [],
				'filename' => null,
			];
		}, 10, 3 );

		SyncWebhook::send_webhook( get_current_blog_id() );

		$this->assertNotNull( $captured_url, 'send_webhook() must fire an HTTP call when sync is configured.' );
		$this->assertStringContainsString( '/destination/site-jobs/42/sync-notify', $captured_url );
		$this->assertSame( 'Bearer dest-api-key', $captured_args['headers']['Authorization'] );

		$body = json_decode( $captured_args['body'], true );
		$this->assertSame( 'the-sync-token', $body['sync_webhook_token'] );
	}

	public function test_send_webhook_is_a_no_op_when_sync_not_configured(): void {
		update_site_option( 'hbm_dest_url', 'https://destination.example.com' );
		update_site_option( 'hbm_dest_key', 'dest-api-key' );

		$called = false;
		add_filter( 'pre_http_request', function ( $preempt ) use ( &$called ) {
			$called = true;
			return $preempt;
		}, 10, 1 );

		SyncWebhook::send_webhook( get_current_blog_id() );

		$this->assertFalse( $called, 'No HTTP call should be attempted when this blog has no sync config on file.' );
	}

	public function test_send_webhook_is_a_no_op_when_destination_credentials_missing(): void {
		$this->configure_sync();
		// hbm_dest_url / hbm_dest_key intentionally left unset.

		$called = false;
		add_filter( 'pre_http_request', function ( $preempt ) use ( &$called ) {
			$called = true;
			return $preempt;
		}, 10, 1 );

		SyncWebhook::send_webhook( get_current_blog_id() );

		$this->assertFalse( $called );
	}

	// -------------------------------------------------------------------------
	// receive_token() — the receiving end of the token-delivery gap this unit closes.
	// See Source\SyncWebhook's class docblock for the full rationale: destination's Enable
	// Sync action calls this so source learns the per-site-job sync_webhook_token it needs
	// to fire the calls exercised above.
	// -------------------------------------------------------------------------

	private function token_request( array $params ): \WP_REST_Response {
		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/source/sync-webhook-token' );
		foreach ( $params as $key => $value ) {
			$req->set_param( $key, $value );
		}
		return SyncWebhook::receive_token( $req );
	}

	public function test_receive_token_stores_config_for_valid_payload(): void {
		$blog_id = get_current_blog_id();

		$response = $this->token_request( [
			'blog_id'            => $blog_id,
			'site_job_id'        => 7,
			'sync_webhook_token' => 'delivered-token',
		] );

		$this->assertSame( 200, $response->get_status() );

		$stored = get_blog_option( $blog_id, 'hbm_sync_config' );
		$this->assertSame( 7, $stored['site_job_id'] );
		$this->assertSame( 'delivered-token', $stored['token'] );
	}

	public function test_receive_token_rejects_missing_fields(): void {
		$response = $this->token_request( [ 'blog_id' => get_current_blog_id() ] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_receive_token_rejects_unknown_blog_id(): void {
		$response = $this->token_request( [
			'blog_id'            => 999999999,
			'site_job_id'        => 7,
			'sync_webhook_token' => 'token',
		] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_receive_token_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/' . HBM_API_NAMESPACE . '/source/sync-webhook-token', $routes );
	}

	public function test_receive_token_endpoint_with_no_auth_returns_error(): void {
		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/source/sync-webhook-token' );
		$req->set_param( 'blog_id', get_current_blog_id() );
		$req->set_param( 'site_job_id', 7 );
		$req->set_param( 'sync_webhook_token', 'token' );
		// No Authorization header — ApiAuth::verify_request() should fail via permission_callback.
		$response = rest_get_server()->dispatch( $req );
		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}
}
