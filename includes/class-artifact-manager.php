<?php

namespace HBMigrator;

class ArtifactManager {

	public static function get_export_dir(): string {
		return wp_upload_dir()['basedir'] . '/hbm-exports/';
	}

	public static function get_staging_dir(): string {
		return wp_upload_dir()['basedir'] . '/hbm-staging/';
	}

	/**
	 * Create the export directory and write security files.
	 * Safe to call multiple times (idempotent).
	 */
	public static function create_export_directory(): bool {
		$dir = self::get_export_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		self::write_htaccess( $dir );
		self::write_index_stub( $dir );
		return true;
	}

	private static function write_htaccess( string $dir ): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$rules = [
			'<IfModule mod_authz_core.c>',
			'    Require all denied',
			'</IfModule>',
			'<IfModule !mod_authz_core.c>',
			'    Order deny,allow',
			'    Deny from all',
			'</IfModule>',
		];
		insert_with_markers( $dir . '.htaccess', 'hb-migrator', $rules );
	}

	private static function write_index_stub( string $dir ): void {
		$file = $dir . 'index.php';
		if ( ! file_exists( $file ) ) {
			file_put_contents( $file, '<?php // Silence is golden.' );
		}
	}

	/**
	 * Return metadata for all downloadable artifacts.
	 *
	 * @return array<array{filename: string, size: int, download_url: string}>
	 */
	public static function list_artifacts(): array {
		$dir = self::get_export_dir();
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$artifacts = [];
		$exts      = [ 'sql', 'xml', 'tar.gz' ];
		foreach ( glob( $dir . '*' ) ?: [] as $path ) {
			$filename = basename( $path );
			$ext_match = false;
			foreach ( $exts as $ext ) {
				$suffix = '.' . $ext;
				if ( substr( $filename, -strlen( $suffix ) ) === $suffix ) {
					$ext_match = true;
					break;
				}
			}
			if ( ! $ext_match || ! is_file( $path ) ) {
				continue;
			}
			$artifacts[] = [
				'filename'     => $filename,
				'size'         => filesize( $path ),
				'download_url' => admin_url( 'tools.php?page=hb-migrator&action=download&file=' . rawurlencode( $filename ) . '&_wpnonce=' . wp_create_nonce( 'hbm_download_' . $filename ) ),
			];
		}
		return $artifacts;
	}

	/** Remove all export artifacts and staging directories. */
	public static function delete_all_artifacts(): void {
		$export_dir = self::get_export_dir();
		if ( is_dir( $export_dir ) ) {
			foreach ( glob( $export_dir . '*' ) ?: [] as $file ) {
				if ( is_file( $file ) && 'index.php' !== basename( $file ) && '.htaccess' !== basename( $file ) ) {
					@unlink( $file );
				}
			}
		}
		$staging_dir = self::get_staging_dir();
		if ( is_dir( $staging_dir ) ) {
			self::remove_directory( $staging_dir );
		}
	}

	/** Recursively remove a directory. */
	public static function remove_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( glob( $dir . '/*' ) ?: [] as $item ) {
			is_dir( $item ) ? self::remove_directory( $item ) : @unlink( $item );
		}
		@rmdir( $dir );
	}

	/**
	 * Verify that the uploads directory is a writable local path (not a CDN-
	 * backed or offloaded directory). Returns a WP_Error on failure.
	 *
	 * @return true|\WP_Error
	 */
	public static function preflight_uploads_directory() {
		$basedir = wp_upload_dir()['basedir'];
		if ( ! is_dir( $basedir ) ) {
			return new \WP_Error( 'hbm_no_uploads_dir', __( 'The uploads directory does not exist locally. Media files may be offloaded to a remote storage service.', 'hb-migrator' ) );
		}
		if ( ! is_writable( $basedir ) ) {
			return new \WP_Error( 'hbm_uploads_not_writable', __( 'The uploads directory is not writable.', 'hb-migrator' ) );
		}
		return true;
	}
}
