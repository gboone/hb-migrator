<?php

namespace HBMigrator\Cli;

use HBMigrator\MigrationRegistry;

/**
 * WP-CLI commands for inspecting and repairing a migration's stored source_api_key.
 *
 * Destination stores exactly one copy of source's API key per migration
 * (`hbm_migrations.source_api_key`), captured once at Start Migration time
 * (Admin\AdminPage::handle_start_migration()) and reused for every authenticated call
 * back to source — both the initial migration pipeline (PostReader, CommentReader,
 * etc.) and ongoing post-migration sync (PostSyncStage, CommentSyncStage, etc.) read
 * this same field via MigrationRegistry::get_migration(). If source's own key ever
 * changes independently of this stored copy — a DB restore, a site clone, a manual
 * reset of source's `hbm_api_key` option — the two fall out of sync and every
 * source-bound request starts failing with a 401 (see SourceClient::get()/post()).
 * These commands give an operator with shell access a safe way to inspect and repair
 * that value directly, instead of hand-writing SQL against hbm_migrations.
 */
class MigrationKeyCommand {

	/**
	 * Shows the source_api_key currently stored for a migration, alongside its
	 * source_url and status for context.
	 *
	 * ## OPTIONS
	 *
	 * <migration_id>
	 * : The hbm_migrations row ID. If you only know the affected site's domain, look
	 * it up first via `SELECT migration_id FROM hbm_site_jobs WHERE source_domain =
	 * '...'` — one destination migration can cover several source site jobs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hbm migration source-key get 42
	 *
	 * @param array $args Positional arguments.
	 */
	public function get( array $args ): void {
		$migration = $this->require_migration( $args[0] );

		\WP_CLI::line( sprintf( 'Migration ID:   %d', $migration->id ) );
		\WP_CLI::line( sprintf( 'Source URL:     %s', $migration->source_url ) );
		\WP_CLI::line( sprintf( 'Status:         %s', $migration->status ) );
		\WP_CLI::line( sprintf(
			'Source API key: %s',
			'' !== $migration->source_api_key ? $migration->source_api_key : '(empty)'
		) );
	}

	/**
	 * Updates the source_api_key stored for a migration — use this to repair a
	 * destination/source key mismatch (e.g. after source's key was reset or the
	 * migration's copy went stale).
	 *
	 * ## OPTIONS
	 *
	 * <migration_id>
	 * : The hbm_migrations row ID to update.
	 *
	 * <key>
	 * : The new source_api_key value. Copy this from source's own `hbm_api_key`
	 * network option — run `wp option get hbm_api_key` against source's main network
	 * site, not a subsite.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hbm migration source-key update 42 3f9c1e...a04b
	 *
	 * @param array $args Positional arguments.
	 */
	public function update( array $args ): void {
		[ $migration_id_raw, $key ] = $args;
		$migration = $this->require_migration( $migration_id_raw );

		$key = trim( $key );
		if ( '' === $key ) {
			\WP_CLI::error( 'The new key must not be empty — use `delete` to clear it instead.' );
		}

		MigrationRegistry::update_migration( (int) $migration->id, [ 'source_api_key' => $key ] );
		\WP_CLI::success( sprintf(
			'Updated source_api_key for migration %d (%s).',
			$migration->id,
			$migration->source_url
		) );
	}

	/**
	 * Clears the source_api_key stored for a migration. Every further authenticated
	 * call to source — the initial migration pipeline or ongoing sync — will fail
	 * with a 401 until a new key is set via `update`.
	 *
	 * ## OPTIONS
	 *
	 * <migration_id>
	 * : The hbm_migrations row ID to clear.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hbm migration source-key delete 42
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (flags).
	 */
	public function delete( array $args, array $assoc_args ): void {
		$migration = $this->require_migration( $args[0] );

		\WP_CLI::confirm(
			sprintf(
				'Clear source_api_key for migration %d (%s)? Sync and any further pulls from source will start failing with 401s until a new key is set.',
				$migration->id,
				$migration->source_url
			),
			$assoc_args
		);

		MigrationRegistry::update_migration( (int) $migration->id, [ 'source_api_key' => '' ] );
		\WP_CLI::success( sprintf( 'Cleared source_api_key for migration %d.', $migration->id ) );
	}

	/**
	 * Resolves a raw CLI argument to an existing migration row, or halts with a clean
	 * WP_CLI error (rather than a raw type/DB error) when it doesn't resolve to one.
	 */
	private function require_migration( string $migration_id_raw ): object {
		$migration_id = self::resolve_migration_id( $migration_id_raw );
		$migration    = null !== $migration_id ? MigrationRegistry::get_migration( $migration_id ) : null;

		if ( ! $migration ) {
			\WP_CLI::error( sprintf( 'No migration found with ID "%s".', $migration_id_raw ) );
		}

		return $migration;
	}

	/**
	 * Validates that a raw CLI argument is a positive integer migration ID, without
	 * touching the database or WP_CLI — kept separate from require_migration() so this
	 * shape check is testable in a plain PHPUnit environment (WP_CLI::error() halts
	 * execution, which isn't something a unit test can safely exercise).
	 */
	public static function resolve_migration_id( string $raw ): ?int {
		return ctype_digit( $raw ) && '0' !== $raw ? (int) $raw : null;
	}
}
