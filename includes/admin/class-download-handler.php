<?php

namespace HBMigrator\Admin;

use HBMigrator\ArtifactManager;

class DownloadHandler {

	public static function init(): void {
		add_action( 'admin_init', [ self::class, 'maybe_stream_file' ] );
	}

	public static function maybe_stream_file(): void {
		if ( 'download' !== ( $_GET['action'] ?? '' ) ) {
			return;
		}
		if ( empty( $_GET['page'] ) || 'hb-migrator' !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download export files.', 'hb-migrator' ), 403 );
		}

		$filename = sanitize_file_name( wp_unslash( $_GET['file'] ?? '' ) );
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'hbm_download_' . $filename ) ) {
			wp_die( esc_html__( 'Nonce verification failed.', 'hb-migrator' ), 403 );
		}

		$export_dir = ArtifactManager::get_export_dir();

		// basename() strips path separators; realpath() confirms the resolved
		// absolute path is still within the export directory. Both checks are
		// required — basename() alone cannot block symlinks or unusual paths.
		$candidate = $export_dir . basename( $filename );
		$resolved  = realpath( $candidate );
		if ( false === $resolved || 0 !== strpos( $resolved, realpath( $export_dir ) ) ) {
			wp_die( esc_html__( 'Invalid file path.', 'hb-migrator' ), 400 );
		}

		if ( ! is_file( $resolved ) || ! is_readable( $resolved ) ) {
			wp_die( esc_html__( 'File not found.', 'hb-migrator' ), 404 );
		}

		self::stream_file( $resolved, basename( $resolved ) );
		exit;
	}

	private static function stream_file( string $path, string $basename ): void {
		$size = filesize( $path );
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $basename ) . '"' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Content-Length: ' . $size );
		header( 'Pragma: no-cache' );
		header( 'Cache-Control: must-revalidate' );
		if ( ob_get_level() ) {
			ob_end_clean();
		}
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	}
}
