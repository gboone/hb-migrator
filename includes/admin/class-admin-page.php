<?php

namespace HBMigrator\Admin;

use HBMigrator\ApiAuth;
use HBMigrator\MigrationRegistry;

class AdminPage {

	public static function init(): void {
		add_action( 'network_admin_menu', [ self::class, 'register_page' ] );
		add_action( 'admin_menu', [ self::class, 'register_page' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_post_hbm_save_config', [ self::class, 'handle_save_config' ] );
		add_action( 'admin_post_hbm_start_migration', [ self::class, 'handle_start_migration' ] );
		add_action( 'admin_post_hbm_clear_migration', [ self::class, 'handle_clear_migration' ] );
	}

	public static function register_page(): void {
		add_management_page(
			__( 'HB Migrator', 'hb-migrator' ),
			__( 'HB Migrator', 'hb-migrator' ),
			'manage_options',
			'hb-migrator',
			[ self::class, 'render_page' ]
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( 'tools_page_hb-migrator' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'hb-migrator-admin', HBM_PLUGIN_URL . 'assets/css/admin.css', [], HBM_VERSION );
		wp_enqueue_script( 'hb-migrator-admin', HBM_PLUGIN_URL . 'assets/js/admin.js', [], HBM_VERSION, true );
		wp_localize_script( 'hb-migrator-admin', 'hbmAdmin', [
			'statusEndpoint' => rest_url( HBM_API_NAMESPACE . '/source/migration-status' ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'activeMigration' => (bool) get_site_option( 'hbm_active_migration' ),
		] );
	}

	public static function render_page(): void {
		$api_key   = ApiAuth::get_or_create_key();
		$dest_url  = get_site_option( 'hbm_dest_url', '' );
		$dest_key  = get_site_option( 'hbm_dest_key', '' );
		$dest_email = get_site_option( 'hbm_dest_email', '' );
		$active    = get_site_option( 'hbm_active_migration' );
		$sites     = is_multisite() ? get_sites( [ 'number' => 0, 'deleted' => 0 ] ) : [];
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
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( urldecode( $_GET['error'] ) ); ?></p></div>
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
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="hbm-migration-form">
						<input type="hidden" name="action" value="hbm_start_migration">
						<?php wp_nonce_field( 'hbm_start_migration' ); ?>

						<h3><?php esc_html_e( 'Select Sites to Migrate', 'hb-migrator' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Archived sites are deselected by default.', 'hb-migrator' ); ?></p>

						<table class="widefat hbm-site-table">
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
									$blogname = get_option( 'blogname' );
									$is_archived = (bool) $site->archived;
									restore_current_blog();
								?>
								<tr>
									<td><input type="checkbox" name="site_ids[]" value="<?php echo (int) $site->blog_id; ?>" <?php checked( ! $is_archived ); ?>></td>
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
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Start Migration', 'hb-migrator' ); ?></button>
						</p>
					</form>
				<?php elseif ( ! $dest_url || ! $dest_key ) : ?>
					<p class="description"><?php esc_html_e( 'Enter and save your destination URL and API key above to select sites.', 'hb-migrator' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_save_config(): void {
		check_admin_referer( 'hbm_save_config' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'hb-migrator' ), 403 );
		}
		update_site_option( 'hbm_dest_url', esc_url_raw( wp_unslash( $_POST['hbm_dest_url'] ?? '' ) ) );
		update_site_option( 'hbm_dest_key', sanitize_text_field( wp_unslash( $_POST['hbm_dest_key'] ?? '' ) ) );
		update_site_option( 'hbm_dest_email', sanitize_email( wp_unslash( $_POST['hbm_dest_email'] ?? '' ) ) );
		wp_redirect( admin_url( 'tools.php?page=hb-migrator&saved=1' ) );
		exit;
	}

	public static function handle_start_migration(): void {
		check_admin_referer( 'hbm_start_migration' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'hb-migrator' ), 403 );
		}

		$dest_url  = get_site_option( 'hbm_dest_url' );
		$dest_key  = get_site_option( 'hbm_dest_key' );
		$dest_email = get_site_option( 'hbm_dest_email', '' );
		$site_ids  = array_map( 'intval', (array) ( $_POST['site_ids'] ?? [] ) );

		if ( ! $dest_url || ! $dest_key || empty( $site_ids ) ) {
			wp_redirect( admin_url( 'tools.php?page=hb-migrator&error=' . rawurlencode( 'Destination URL, API key, and at least one site are required.' ) ) );
			exit;
		}

		$source_api_key = \HBMigrator\ApiAuth::get_or_create_key();

		$response = wp_remote_post(
			trailingslashit( $dest_url ) . 'wp-json/' . HBM_API_NAMESPACE . '/destination/begin',
			[
				'headers'   => [
					'Authorization' => 'Bearer ' . $dest_key,
					'Content-Type'  => 'application/json',
				],
				'body'      => wp_json_encode( [
					'source_url'         => network_site_url(),
					'source_api_key'     => $source_api_key,
					'site_ids'           => $site_ids,
					'notification_email' => $dest_email,
				] ),
				'timeout'   => 30,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_redirect( admin_url( 'tools.php?page=hb-migrator&error=' . rawurlencode( $response->get_error_message() ) ) );
			exit;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 !== $code || empty( $body['migration_id'] ) ) {
			$msg = $body['error'] ?? "Destination returned HTTP $code";
			wp_redirect( admin_url( 'tools.php?page=hb-migrator&error=' . rawurlencode( $msg ) ) );
			exit;
		}

		update_site_option( 'hbm_active_migration', [
			'migration_id' => (int) $body['migration_id'],
			'dest_url'     => $dest_url,
			'dest_key'     => $dest_key,
		] );

		wp_redirect( admin_url( 'tools.php?page=hb-migrator&started=1' ) );
		exit;
	}

	public static function handle_clear_migration(): void {
		check_admin_referer( 'hbm_clear_migration' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'hb-migrator' ), 403 );
		}
		delete_site_option( 'hbm_active_migration' );
		wp_redirect( admin_url( 'tools.php?page=hb-migrator' ) );
		exit;
	}
}
