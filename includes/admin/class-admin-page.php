<?php

namespace HBMigrator\Admin;

use HBMigrator\ArtifactManager;
use HBMigrator\Checkpoint;
use HBMigrator\PipelineController;
use HBMigrator\QueueTable;

class AdminPage {

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'register_page' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_post_hbm_start_export', [ self::class, 'handle_start_export' ] );
		add_action( 'admin_post_hbm_retry_stage', [ self::class, 'handle_retry_stage' ] );
		add_action( 'admin_post_hbm_reset_export', [ self::class, 'handle_reset_export' ] );
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
		wp_enqueue_style(
			'hb-migrator-admin',
			HBM_PLUGIN_URL . 'assets/css/admin.css',
			[],
			HBM_VERSION
		);
		wp_enqueue_script(
			'hb-migrator-admin',
			HBM_PLUGIN_URL . 'assets/js/admin.js',
			[ 'wp-api-request' ],
			HBM_VERSION,
			true
		);
		wp_localize_script( 'hb-migrator-admin', 'hbmAdmin', [
			'progressEndpoint' => rest_url( 'hb-migrator/v1/progress' ),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
		] );
	}

	public static function render_page(): void {
		QueueTable::maybe_create_or_upgrade();
		ArtifactManager::create_export_directory();
		$stages    = Checkpoint::get_all_stages();
		$running   = false;
		$artifacts = ArtifactManager::list_artifacts();

		foreach ( $stages as $row ) {
			if ( 'running' === $row->status ) {
				$running = true;
				break;
			}
		}

		// Preflight checks.
		$phar_ok         = extension_loaded( 'Phar' );
		$uploads_ok      = ArtifactManager::preflight_uploads_directory();
		$uploads_ok_bool = ( true === $uploads_ok );

		$disk_free = @disk_free_space( wp_upload_dir()['basedir'] );

		?>
		<div class="wrap" id="hbm-wrap" <?php echo $running ? 'data-export-running="1"' : ''; ?>>
			<h1><?php esc_html_e( 'HB Migrator', 'hb-migrator' ); ?></h1>
			<p><?php esc_html_e( 'Export this site\'s database, content, and media for migration to WordPress VIP.', 'hb-migrator' ); ?></p>

			<?php if ( ! $phar_ok ) : ?>
				<div class="notice notice-error"><p><strong><?php esc_html_e( 'Notice:', 'hb-migrator' ); ?></strong> <?php esc_html_e( 'The Phar PHP extension is not enabled on this server. Media archives cannot be created. Please enable Phar to use the full export pipeline.', 'hb-migrator' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $uploads_ok_bool ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $uploads_ok->get_error_message() ); ?></p></div>
			<?php endif; ?>

			<?php if ( false !== $disk_free && $disk_free < 512 * 1024 * 1024 ) : ?>
				<div class="notice notice-warning"><p><?php printf( esc_html__( 'Low disk space: only %s available in the uploads directory.', 'hb-migrator' ), esc_html( size_format( $disk_free ) ) ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $stages ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="hbm_start_export">
					<?php wp_nonce_field( 'hbm_start_export' ); ?>
					<p><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Start Export', 'hb-migrator' ); ?>"></p>
				</form>
			<?php else : ?>
				<h2><?php esc_html_e( 'Export Status', 'hb-migrator' ); ?></h2>
				<table class="hbm-stages widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Stage', 'hb-migrator' ); ?></th>
							<th><?php esc_html_e( 'Status', 'hb-migrator' ); ?></th>
							<th><?php esc_html_e( 'Progress', 'hb-migrator' ); ?></th>
							<th><?php esc_html_e( 'Attempts', 'hb-migrator' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'hb-migrator' ); ?></th>
						</tr>
					</thead>
					<tbody id="hbm-stage-rows">
						<?php foreach ( $stages as $row ) : ?>
						<?php
						$pct = ( $row->total_items > 0 )
							? min( 100, (int) round( $row->batch_offset / $row->total_items * 100 ) )
							: 0;
						?>
						<tr id="hbm-stage-<?php echo esc_attr( $row->stage ); ?>" data-status="<?php echo esc_attr( $row->status ); ?>">
							<td><?php echo esc_html( strtoupper( $row->stage ) ); ?></td>
							<td><span class="hbm-status hbm-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( ucfirst( $row->status ) ); ?></span></td>
							<td>
								<div class="hbm-progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr( $pct ); ?>" aria-valuemin="0" aria-valuemax="100">
									<div class="hbm-progress-fill" style="width:<?php echo (int) $pct; ?>%"></div>
								</div>
								<span class="hbm-progress-label">
									<?php if ( $row->total_items > 0 ) : ?>
										<?php echo esc_html( number_format_i18n( (int) $row->batch_offset ) . ' / ' . number_format_i18n( (int) $row->total_items ) ); ?>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</span>
							</td>
							<td><?php echo (int) $row->attempt_count; ?></td>
							<td>
								<?php if ( 'failed' === $row->status ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hbm-inline-form">
										<input type="hidden" name="action" value="hbm_retry_stage">
										<input type="hidden" name="stage" value="<?php echo esc_attr( $row->stage ); ?>">
										<?php wp_nonce_field( 'hbm_retry_stage_' . $row->stage ); ?>
										<button type="submit" class="button button-secondary"><?php esc_html_e( 'Retry Stage', 'hb-migrator' ); ?></button>
									</form>
									<?php if ( $row->error_message ) : ?>
										<p class="hbm-error-message"><?php echo esc_html( $row->error_message ); ?></p>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="hbm-reset-form">
						<input type="hidden" name="action" value="hbm_reset_export">
						<?php wp_nonce_field( 'hbm_reset_export' ); ?>
						<button type="submit" class="button button-secondary hbm-reset-btn" data-confirm="<?php esc_attr_e( 'Are you sure you want to reset the export? All progress and artifact files will be deleted. This cannot be undone.', 'hb-migrator' ); ?>"><?php esc_html_e( 'Reset Export', 'hb-migrator' ); ?></button>
					</form>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $artifacts ) ) : ?>
				<h2><?php esc_html_e( 'Download Artifacts', 'hb-migrator' ); ?></h2>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'File', 'hb-migrator' ); ?></th>
							<th><?php esc_html_e( 'Size', 'hb-migrator' ); ?></th>
							<th><?php esc_html_e( 'Download', 'hb-migrator' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $artifacts as $artifact ) : ?>
						<tr>
							<td><?php echo esc_html( $artifact['filename'] ); ?></td>
							<td><?php echo esc_html( size_format( $artifact['size'] ) ); ?></td>
							<td><a class="button" href="<?php echo esc_url( $artifact['download_url'] ); ?>"><?php esc_html_e( 'Download', 'hb-migrator' ); ?></a></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Admin-post action handlers
	// -----------------------------------------------------------------------

	public static function handle_start_export(): void {
		check_admin_referer( 'hbm_start_export' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'hb-migrator' ), 403 );
		}
		PipelineController::start_export();
		wp_redirect( admin_url( 'tools.php?page=hb-migrator&started=1' ) );
		exit;
	}

	public static function handle_retry_stage(): void {
		$stage = sanitize_key( wp_unslash( $_POST['stage'] ?? '' ) );
		check_admin_referer( 'hbm_retry_stage_' . $stage );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'hb-migrator' ), 403 );
		}
		// PipelineController::retry_stage() validates $stage against the allowlist.
		PipelineController::retry_stage( $stage );
		wp_redirect( admin_url( 'tools.php?page=hb-migrator&retried=1' ) );
		exit;
	}

	public static function handle_reset_export(): void {
		check_admin_referer( 'hbm_reset_export' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'hb-migrator' ), 403 );
		}
		PipelineController::reset();
		wp_redirect( admin_url( 'tools.php?page=hb-migrator&reset=1' ) );
		exit;
	}
}
