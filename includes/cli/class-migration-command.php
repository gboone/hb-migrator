<?php

namespace HBMigrator\Cli;

use HBMigrator\MigrationRegistry;

/**
 * WP-CLI commands for browsing migrations. See Cli\MigrationKeyCommand for inspecting
 * and repairing a specific migration's source_api_key.
 */
class MigrationCommand {

	/**
	 * Lists every migration — status, source URL, and a per-migration summary of its
	 * site jobs' domains and most recent sync pass — so an operator can find a
	 * migration_id (or the right --domain value for `source-key`) without writing SQL.
	 *
	 * One row per migration, not per site job: a migration's own hbm_migrations row has
	 * no domain or per-site sync-timestamp columns (those only exist at the site-job
	 * level — see MigrationRegistry::summarize_site_jobs()), so a migration covering
	 * several site jobs shows every distinct domain on each side, comma-separated.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a different format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp hbm migration list
	 *     wp hbm migration list --format=csv
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (flags).
	 */
	public function list( array $args, array $assoc_args ): void {
		$rows = [];
		foreach ( MigrationRegistry::list_migrations() as $migration ) {
			$summary = MigrationRegistry::summarize_site_jobs( (int) $migration->id );

			$rows[] = [
				'migration_id'   => (int) $migration->id,
				'status'         => $migration->status,
				'source_url'     => $migration->source_url,
				'source_domains' => $summary['source_domains'] ? implode( ', ', $summary['source_domains'] ) : '(none)',
				'dest_domains'   => $summary['dest_domains'] ? implode( ', ', $summary['dest_domains'] ) : '(unassigned)',
				'last_sync'      => $summary['last_sync'] ?? 'Never',
			];
		}

		\WP_CLI\Utils\format_items(
			\WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ),
			$rows,
			[ 'migration_id', 'status', 'source_url', 'source_domains', 'dest_domains', 'last_sync' ]
		);
	}
}
