<?php

namespace HBMigrator\Admin;

use HBMigrator\ApiAuth;
use HBMigrator\MigrationRegistry;

class AdminPage {

	public static function init(): void {
		add_action( 'network_admin_menu', [ self::class, 'register_page' ] );
		add_action( 'network_admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_enqueue_scripts',         [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_post_hbm_save_config', [ self::class, 'handle_save_config' ] );
		add_action( 'admin_post_hbm_start_migration', [ self::class, 'handle_start_migration' ] );
		add_action( 'admin_post_hbm_clear_migration', [ self::class, 'handle_clear_migration' ] );
	}

	public static function register_page(): void {
		add_submenu_page(
			'settings.php',
			__( 'HB Migrator', 'hb-migrator' ),
			__( 'HB Migrator', 'hb-migrator' ),
			'manage_network',
			'hb-migrator',
			[ self::class, 'render_page' ]
		);
	}

	public static function enqueue_assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'hb-migrator' ) ) {
			return;
		}
		wp_enqueue_style( 'hb-migrator-admin', HBM_PLUGIN_URL . 'assets/css/admin.css', [], HBM_VERSION );
		wp_enqueue_script( 'hb-migrator-admin', HBM_PLUGIN_URL . 'assets/js/admin.js', [], HBM_VERSION, true );
		wp_localize_script( 'hb-migrator-admin', 'hbmAdmin', [
			'statusEndpoint'   => rest_url( HBM_API_NAMESPACE . '/source/migration-status' ),
			'preflightEndpoint' => rest_url( HBM_API_NAMESPACE . '/source/run-preflight' ),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
			'activeMigration'  => (bool) get_site_option( 'hbm_active_migration' ),
		] );
	}

	public static function render_page(): void {
		$api_key    = ApiAuth::get_or_create_key();
		$dest_url   = get_site_option( 'hbm_dest_url', '' );
		$dest_key   = get_site_option( 'hbm_dest_key', '' );
		$dest_email = get_site_option( 'hbm_dest_email', '' );
		$active     = get_site_option( 'hbm_active_migration' );
		$sites      = is_multisite() ? get_sites( [ 'number' => 0, 'deleted' => 0 ] ) : [];
		?>
		<div class="wrap" id="hbm-wrap">
			<h1><?php esc_html_e( 'HB Migrator', 'hb-migrator' ); ?></h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuration saved.', 'hb-migrator' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['started'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Migration started.', 'hb-migrator' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( urldecode( wp_unslash( $_GET['error'] ) ) ); ?></p></div>
			<?php endif; ?>

			<!-- Section 1: This site's API key -->
			<h2><?php esc_html_e( 'This Site', 'hb-migrator' ); ?></h2>
			<p><?php esc_html_e( 'Install HB Migrator on a source site and enter this URL and API key to migrate content here.', 'hb-migrator' ); ?></p>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'This site URL', 'hb-migrator' ); ?></th>
					<td><code><?php echo esc_html( network_site_url() ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'This site\'s API key', 'hb-migrator' ); ?></th>
					<td>
						<input type="text" class="regular-text" id="hbm-api-key" value="<?php echo esc_attr( $api_key ); ?>" readonly>
						<button type="button" class="button" id="hbm-copy-key"><?php esc_html_e( 'Copy', 'hb-migrator' ); ?></button>
					</td>
				</tr>
			</table>

			<hr>

			<!-- Section 2: Migrate from a source -->
			<h2><?php esc_html_e( 'Migrate FROM a Source Site', 'hb-migrator' ); ?></h2>

			<?php if ( $active ) : ?>
				<div class="notice notice-info" id="hbm-active-notice">
					<p><?php esc_html_e( 'A migration is in progress. See status below.', 'hb-migrator' ); ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="hbm_clear_migration">
						<?php wp_nonce_field( 'hbm_clear_migration' ); ?>
						<button type="submit" class="button button-small"><?php esc_html_e( 'Clear', 'hb-migrator' ); ?></button>
					</form>
					</p>
				</div>
				<div id="hbm-progress-wrap" data-polling="1">
					<p><?php esc_html_e( 'Loading migration status…', 'hb-migrator' ); ?></p>
				</div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="hbm_save_config">
					<?php wp_nonce_field( 'hbm_save_config' ); ?>
					<table class="form-table">
						<tr>
							<th><label for="hbm-dest-url"><?php esc_html_e( 'Destination URL', 'hb-migrator' ); ?></label></th>
							<td><input type="url" id="hbm-dest-url" name="hbm_dest_url" class="regular-text" value="<?php echo esc_attr( $dest_url ); ?>" required></td>
						</tr>
						<tr>
							<th><label for="hbm-dest-key"><?php esc_html_e( 'Destination API Key', 'hb-migrator' ); ?></label></th>
							<td><input type="text" id="hbm-dest-key" name="hbm_dest_key" class="regular-text" value="<?php echo esc_attr( $dest_key ); ?>" required></td>
						</tr>
						<tr>
							<th><label for="hbm-dest-email"><?php esc_html_e( 'Notify email (optional)', 'hb-migrator' ); ?></label></th>
							<td><input type="email" id="hbm-dest-email" name="hbm_dest_email" class="regular-text" value="<?php echo esc_attr( $dest_email ); ?>"></td>
						</tr>
					</table>
					<p class="submit"><button type="submit" class="button button-secondary"><?php esc_html_e( 'Save Configuration', 'hb-migrator' ); ?></button></p>
				</form>

				<?php if ( $dest_url && $dest_key && ! empty( $sites ) ) : ?>
					<h3><?php esc_html_e( 'Select Sites to Migrate', 'hb-migrator' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Archived sites are deselected by default.', 'hb-migrator' ); ?></p>

					<table class="widefat hbm-site-table" id="hbm-site-select-table">
						<thead>
							<tr>
								<th style="width:30px"></th>
								<th><?php esc_html_e( 'Domain', 'hb-migrator' ); ?></th>
								<th><?php esc_html_e( 'Name', 'hb-migrator' ); ?></th>
								<th><?php esc_html_e( 'Path', 'hb-migrator' ); ?></th>
								<th><?php esc_html_e( 'Status', 'hb-migrator' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $sites as $site ) :
								switch_to_blog( $site->blog_id );
								$blogname    = get_option( 'blogname' );
								$is_archived = (bool) $site->archived;
								restore_current_blog();
							?>
							<tr>
								<td><input type="checkbox" class="hbm-site-checkbox" value="<?php echo (int) $site->blog_id; ?>" <?php checked( ! $is_archived ); ?>></td>
								<td><?php echo esc_html( $site->domain ); ?></td>
								<td><?php echo esc_html( $blogname ); ?></td>
								<td><?php echo esc_html( $site->path ); ?></td>
								<td>
									<?php if ( $is_archived ) : ?>
										<span class="hbm-status hbm-status-archived"><?php esc_html_e( 'Archived', 'hb-migrator' ); ?></span>
									<?php else : ?>
										<span class="hbm-status hbm-status-complete"><?php esc_html_e( 'Active', 'hb-migrator' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p class="submit">
						<button type="button" class="button button-primary" id="hbm-preflight-btn"><?php esc_html_e( 'Run Pre-flight Checks', 'hb-migrator' ); ?></button>
					</p>

					<div id="hbm-preflight-results" hidden></div>

					<div id="hbm-preflight-start" hidden>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="hbm-start-form">
							<input type="hidden" name="action" value="hbm_start_migration">
							<?php wp_nonce_field( 'hbm_start_migration' ); ?>
							<div id="hbm-start-site-ids"></div>
							<input type="hidden" name="user_conflict_policy" id="hbm-user-policy" value="merge">
							<input type="hidden" name="site_conflict_policy"  id="hbm-site-policy"  value="generate_new">

							<fieldset style="margin:1em 0">
								<legend style="font-weight:600"><?php esc_html_e( 'If a media file already exists on the destination:', 'hb-migrator' ); ?></legend>
								<label style="display:block;margin-top:.5em">
									<input type="radio" name="media_conflict_policy" value="import_all" checked>
									<?php esc_html_e( 'Import anyway (allow duplicates)', 'hb-migrator' ); ?>
								</label>
								<label style="display:block;margin-top:.25em">
									<input type="radio" name="media_conflict_policy" value="skip_duplicates">
									<?php esc_html_e( 'Skip — reuse the existing file', 'hb-migrator' ); ?>
								</label>
							</fieldset>

							<fieldset style="margin:1em 0">
								<legend style="font-weight:600"><?php esc_html_e( 'Which media files to import:', 'hb-migrator' ); ?></legend>
								<label style="display:block;margin-top:.5em">
									<input type="radio" name="media_import_scope" value="all" checked>
									<?php esc_html_e( 'All media', 'hb-migrator' ); ?>
								</label>
								<label style="display:block;margin-top:.25em">
									<input type="radio" name="media_import_scope" value="attached_only">
									<?php esc_html_e( 'Only media attached to a post', 'hb-migrator' ); ?>
								</label>
							</fieldset>

							<p class="submit">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Start Migration', 'hb-migrator' ); ?></button>
							</p>
						</form>
					</div>
				<?php elseif ( ! $dest_url || ! $dest_key ) : ?>
					<p class="description"><?php esc_html_e( 'Enter and save your destination URL and API key above to select sites.', 'hb-migrator' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<?php
			$history = (array) get_site_option( 'hbm_migration_history', [] );
			if ( ! empty( $history ) ) :
			?>
			<hr>
			<h2><?php esc_html_e( 'Past Migrations', 'hb-migrator' ); ?></h2>
			<table class="widefat hbm-history-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'hb-migrator' ); ?></th>
						<th><?php esc_html_e( 'Destination', 'hb-migrator' ); ?></th>
						<th><?php esc_html_e( 'Status', 'hb-migrator' ); ?></th>
						<th><?php esc_html_e( 'Sites', 'hb-migrator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$status_labels = [
						'complete' => __( 'Complete', 'hb-migrator' ),
						'running'  => __( 'Running', 'hb-migrator' ),
						'failed'   => __( 'Failed', 'hb-migrator' ),
						'pending'  => __( 'Pending', 'hb-migrator' ),
						'unknown'  => __( 'Unknown', 'hb-migrator' ),
					];
					foreach ( $history as $entry ) :
						$started    = ! empty( $entry['started_at'] ) ? date_i18n( 'Y-m-d H:i', (int) $entry['started_at'] ) : esc_html__( '—', 'hb-migrator' );
						$status_key = $entry['status'] ?? 'unknown';
						$status_lbl = $status_labels[ $status_key ] ?? ucfirst( $status_key );
					?>
					<tr>
						<td><?php echo esc_html( $started ); ?></td>
						<td><?php echo esc_html( $entry['dest_url'] ?? '' ); ?></td>
						<td><span class="hbm-status hbm-status-<?php echo esc_attr( $status_key ); ?>"><?php echo esc_html( $status_lbl ); ?></span></td>
						<td>
							<?php foreach ( (array) ( $entry['sites'] ?? [] ) as $site ) : ?>
								<div class="hbm-history-site">
									<span class="hbm-history-site-label"><?php echo esc_html( $site['source_domain'] ?? '' ); ?> &rarr; <?php echo esc_html( $site['dest_path'] ?? '' ); ?></span>
									<span class="hbm-status hbm-status-<?php echo esc_attr( $site['status'] ?? '' ); ?>"><?php echo esc_html( $status_labels[ $site['status'] ?? '' ] ?? ucfirst( $site['status'] ?? '' ) ); ?></span>
									<?php if ( ! empty( $site['error_message'] ) ) : ?>
										<p class="hbm-error-message"><?php echo esc_html( $site['error_message'] ); ?></p>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

		</div>
		<?php
	}

	public static function handle_save_config(): void {
		check_admin_referer( 'hbm_save_config' );
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'hb-migrator' ), 403 );
		}
		update_site_option( 'hbm_dest_url', esc_url_raw( wp_unslash( $_POST['hbm_dest_url'] ?? '' ) ) );
		update_site_option( 'hbm_dest_key', sanitize_text_field( wp_unslash( $_POST['hbm_dest_key'] ?? '' ) ) );
		update_site_option( 'hbm_dest_email', sanitize_email( wp_unslash( $_POST['hbm_dest_email'] ?? '' ) ) );
		wp_safe_redirect( network_admin_url( 'settings.php?page=hb-migrator&saved=1' ) );
		exit;
	}

	public static function handle_start_migration(): void {
		check_admin_referer( 'hbm_start_migration' );
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'hb-migrator' ), 403 );
		}

		$dest_url   = get_site_option( 'hbm_dest_url' );
		$dest_key   = get_site_option( 'hbm_dest_key' );
		$dest_email = get_site_option( 'hbm_dest_email', '' );
		$site_ids   = array_map( 'intval', (array) ( $_POST['site_ids'] ?? [] ) );

		if ( ! $dest_url || ! $dest_key || empty( $site_ids ) ) {
			wp_safe_redirect( network_admin_url( 'settings.php?page=hb-migrator&error=' . rawurlencode( 'Destination URL, API key, and at least one site are required.' ) ) );
			exit;
		}

		$user_conflict_policy  = sanitize_key( wp_unslash( $_POST['user_conflict_policy']  ?? 'merge' ) );
		$site_conflict_policy  = sanitize_key( wp_unslash( $_POST['site_conflict_policy']  ?? 'generate_new' ) );
		$media_conflict_policy = sanitize_key( wp_unslash( $_POST['media_conflict_policy'] ?? 'import_all' ) );
		$media_import_scope    = sanitize_key( wp_unslash( $_POST['media_import_scope']    ?? 'all' ) );

		$source_api_key = \HBMigrator\ApiAuth::get_or_create_key();

		$response = wp_remote_post(
			trailingslashit( $dest_url ) . 'wp-json/' . HBM_API_NAMESPACE . '/destination/begin',
			[
				'headers'   => [
					'Authorization' => 'Bearer ' . $dest_key,
					'Content-Type'  => 'application/json',
				],
				'body'      => wp_json_encode( [
					'source_url'            => network_site_url(),
					'source_api_key'        => $source_api_key,
					'site_ids'              => $site_ids,
					'notification_email'    => $dest_email,
					'user_conflict_policy'  => $user_conflict_policy,
					'site_conflict_policy'  => $site_conflict_policy,
					'media_conflict_policy' => $media_conflict_policy,
					'media_import_scope'    => $media_import_scope,
				] ),
				'timeout'   => 30,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_safe_redirect( network_admin_url( 'settings.php?page=hb-migrator&error=' . rawurlencode( $response->get_error_message() ) ) );
			exit;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! in_array( $code, [ 200, 201 ], true ) || empty( $body['migration_id'] ) ) {
			$msg = $body['error'] ?? "Destination returned HTTP $code";
			wp_safe_redirect( network_admin_url( 'settings.php?page=hb-migrator&error=' . rawurlencode( $msg ) ) );
			exit;
		}

		update_site_option( 'hbm_active_migration', [
			'migration_id' => (int) $body['migration_id'],
			'status_token' => $body['status_token'] ?? '',
			'dest_url'     => $dest_url,
			'dest_key'     => $dest_key,
			'started_at'   => time(),
		] );

		wp_safe_redirect( network_admin_url( 'settings.php?page=hb-migrator&started=1' ) );
		exit;
	}

	public static function handle_clear_migration(): void {
		check_admin_referer( 'hbm_clear_migration' );
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'hb-migrator' ), 403 );
		}

		$active = get_site_option( 'hbm_active_migration' );
		if ( $active ) {
			self::save_history_entry( self::fetch_migration_status( $active ), $active );

			// Cancel on destination so the old migration record is not restarted if the
			// user starts a fresh migration for the same source. Best-effort — don't block
			// the clear if the destination is temporarily unreachable.
			if ( ! empty( $active['migration_id'] ) && ! empty( $active['dest_url'] ) && ! empty( $active['dest_key'] ) ) {
				wp_remote_post(
					trailingslashit( $active['dest_url'] ) . 'wp-json/' . HBM_API_NAMESPACE . '/destination/migrations/' . (int) $active['migration_id'] . '/cancel',
					[
						'headers'   => [
							'Authorization' => 'Bearer ' . $active['dest_key'],
							'Content-Type'  => 'application/json',
						],
						'body'      => '{}',
						'timeout'   => 10,
						'sslverify' => true,
					]
				);
			}
		}

		delete_site_option( 'hbm_active_migration' );
		wp_safe_redirect( network_admin_url( 'settings.php?page=hb-migrator' ) );
		exit;
	}

	/**
	 * Fetch current status from the destination for the given active migration.
	 * Returns [ 'status' => 'unknown', 'sites' => [] ] on any failure.
	 */
	private static function fetch_migration_status( array $active ): array {
		if ( empty( $active['migration_id'] ) || empty( $active['dest_url'] ) ) {
			return [ 'status' => 'unknown', 'sites' => [] ];
		}
		$host = wp_parse_url( $active['dest_url'], PHP_URL_HOST );
		if ( ! $host ) {
			return [ 'status' => 'unknown', 'sites' => [] ];
		}
		$resolved_ip = gethostbyname( $host );
		if ( ! filter_var( $resolved_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return [ 'status' => 'unknown', 'sites' => [] ];
		}

		$url = add_query_arg(
			[ 'status_token' => $active['status_token'] ?? '' ],
			trailingslashit( $active['dest_url'] ) . 'wp-json/' . HBM_API_NAMESPACE . '/destination/status/' . (int) $active['migration_id']
		);
		$response = wp_remote_get( $url, [
			'headers'   => [ 'Authorization' => 'Bearer ' . ( $active['dest_key'] ?? '' ) ],
			'timeout'   => 15,
			'sslverify' => true,
		] );
		if ( is_wp_error( $response ) ) {
			return [ 'status' => 'unknown', 'sites' => [] ];
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : [ 'status' => 'unknown', 'sites' => [] ];
	}

	/**
	 * Persist a migration summary to hbm_migration_history (last 10 entries).
	 * No-op when there is no active migration or the migration_id is already recorded.
	 *
	 * @param array      $status_body Response body from the destination status endpoint.
	 * @param array|null $active      hbm_active_migration snapshot. When null the option is read
	 *                                from the DB; pass it explicitly to avoid a second read and
	 *                                the TOCTOU race if handle_clear_migration deletes the option
	 *                                between the caller's read and this function's read.
	 */
	public static function save_history_entry( array $status_body, ?array $active = null ): void {
		if ( null === $active ) {
			$active = get_site_option( 'hbm_active_migration' );
		}
		if ( ! $active || empty( $active['migration_id'] ) ) {
			return;
		}

		$migration_id = (int) $active['migration_id'];
		$history      = (array) get_site_option( 'hbm_migration_history', [] );

		foreach ( $history as $entry ) {
			if ( (int) ( $entry['migration_id'] ?? 0 ) === $migration_id ) {
				return; // already recorded — no duplicate
			}
		}

		$sites = [];
		foreach ( (array) ( $status_body['sites'] ?? [] ) as $site ) {
			$sites[] = [
				'source_domain' => $site['source_domain'] ?? '',
				'dest_path'     => $site['dest_path'] ?? '',
				'status'        => $site['status'] ?? 'unknown',
				'error_message' => $site['error_message'] ?? '',
			];
		}

		array_unshift( $history, [
			'migration_id' => $migration_id,
			'dest_url'     => esc_url_raw( $active['dest_url'] ?? '' ),
			'started_at'   => (int) ( $active['started_at'] ?? 0 ),
			'saved_at'     => time(),
			'status'       => $status_body['status'] ?? 'unknown',
			'sites'        => $sites,
		] );

		update_site_option( 'hbm_migration_history', array_slice( $history, 0, 10 ) );
	}
}
