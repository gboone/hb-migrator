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
	 * [<migration_id>]
	 * : The hbm_migrations row ID. Omit this and pass --domain instead if you only know
	 * the affected site's domain. Use `wp hbm migration list` to browse migrations.
	 *
	 * [--domain=<domain>]
	 * : Look up the migration via a site's source or destination domain instead of a
	 * migration ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hbm migration source-key get 42
	 *     wp hbm migration source-key get --domain=blog.example.com
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (flags).
	 */
	public function get( array $args, array $assoc_args ): void {
		$migration = $this->require_migration( $args, $assoc_args );

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
	 * [<migration_id>]
	 * : The hbm_migrations row ID to update. Omit this and pass --domain instead if you
	 * only know the affected site's domain.
	 *
	 * <key>
	 * : The new source_api_key value. Copy this from source's own `hbm_api_key`
	 * network option — run `wp option get hbm_api_key` against source's main network
	 * site, not a subsite.
	 *
	 * [--domain=<domain>]
	 * : Look up the migration via a site's source or destination domain instead of a
	 * migration ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hbm migration source-key update 42 3f9c1e...a04b
	 *     wp hbm migration source-key update --domain=blog.example.com 3f9c1e...a04b
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (flags).
	 */
	public function update( array $args, array $assoc_args ): void {
		// <migration_id> is only present in $args when --domain wasn't used, so <key> is
		// whichever positional comes last. A stray extra positional alongside --domain
		// (e.g. `update 2 newkey --domain=...`) would otherwise silently swallow the
		// migration_id into $key instead of catching the ambiguity — reject it explicitly.
		$has_domain = ! empty( $assoc_args['domain'] );
		if ( $has_domain && count( $args ) > 1 ) {
			\WP_CLI::error( 'Pass either <migration_id> or --domain, not both.' );
		}
		$id_args = $has_domain ? [] : [ $args[0] ?? '' ];
		$key     = $has_domain ? ( $args[0] ?? '' ) : ( $args[1] ?? '' );

		$migration = $this->require_migration( $id_args, $assoc_args );

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
	 * [<migration_id>]
	 * : The hbm_migrations row ID to clear. Omit this and pass --domain instead if you
	 * only know the affected site's domain.
	 *
	 * [--domain=<domain>]
	 * : Look up the migration via a site's source or destination domain instead of a
	 * migration ID.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hbm migration source-key delete 42
	 *     wp hbm migration source-key delete --domain=blog.example.com
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (flags).
	 */
	public function delete( array $args, array $assoc_args ): void {
		$migration = $this->require_migration( $args, $assoc_args );

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
	 * Resolves a migration from either a positional migration_id or a --domain flag, or
	 * halts with a clean WP_CLI error (rather than a raw type/DB error) when neither
	 * resolves to one.
	 *
	 * @param array $id_args    Positional arguments containing only the identifier slot
	 *                          (migration_id), if present — callers with additional
	 *                          positionals (e.g. update()'s <key>) must slice those out
	 *                          before calling this.
	 * @param array $assoc_args Associative arguments (flags) — reads 'domain'.
	 */
	private function require_migration( array $id_args, array $assoc_args ): object {
		$domain           = isset( $assoc_args['domain'] ) ? trim( (string) $assoc_args['domain'] ) : '';
		// Not trimmed — resolve_migration_id() deliberately rejects whitespace-padded
		// input rather than silently cleaning it (see its own tests).
		$migration_id_raw = (string) ( $id_args[0] ?? '' );

		if ( '' !== $domain ) {
			if ( '' !== $migration_id_raw ) {
				\WP_CLI::error( 'Pass either <migration_id> or --domain, not both.' );
			}

			$site_job = MigrationRegistry::find_site_job_by_domain( $domain );
			if ( ! $site_job ) {
				\WP_CLI::error( sprintf( 'No site job found for domain "%s". Use `wp hbm migration list` to browse known domains.', $domain ) );
			}

			$migration = MigrationRegistry::get_migration( (int) $site_job->migration_id );
			if ( ! $migration ) {
				\WP_CLI::error( sprintf(
					'Site job %d for domain "%s" references missing migration %d.',
					$site_job->id,
					$domain,
					$site_job->migration_id
				) );
			}

			return $migration;
		}

		if ( '' === $migration_id_raw ) {
			\WP_CLI::error( 'Pass either <migration_id> or --domain.' );
		}

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
