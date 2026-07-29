<?php

namespace HBMigrator\Cli;

use HBMigrator\Destination\AuditReport;
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
		\WP_CLI\Utils\format_items(
			\WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ),
			self::build_rows(),
			[ 'migration_id', 'status', 'source_url', 'source_domains', 'dest_domains', 'last_sync', 'report_post_ids' ]
		);
	}

	/**
	 * Builds list()'s row data, split out from the WP-CLI rendering call itself so this logic
	 * is testable in a plain PHPUnit environment — \WP_CLI\Utils\format_items() and
	 * \WP_CLI\Utils\get_flag_value() require a real WP-CLI runtime that this repo's test suite
	 * does not provide (see tests/test-migration-key-command.php's identical rationale for
	 * MigrationKeyCommand).
	 *
	 * R1 (docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, "U1. CLI report-post-id
	 * lookup"): adds a `report_post_ids` column — every site job under the migration that has an
	 * `hbm_audit_report` post, as comma-joined `job_id:post_id` pairs (e.g. "12:451, 13:452").
	 * A site job with no report is simply omitted from the list, not shown as a placeholder —
	 * an operator wants to know which reports exist, not enumerate absences. Uses
	 * AuditReport::get_report_post_id_for_site_job() (a non-creating lookup) rather than
	 * get_or_create_for_site_job(), so merely listing migrations never fabricates an empty
	 * report post as a side effect — see that method's own docblock.
	 *
	 * Computed here, not in MigrationRegistry::summarize_site_jobs(): that method dedupes
	 * domains across a migration's site jobs into a handful of distinct values (a genuine
	 * many-to-few aggregation), whereas a report-post-id pairing is inherently 1:1 per site job
	 * and would never dedupe the same way — folding it into that method's contract would strain
	 * an abstraction built for a different shape.
	 */
	public static function build_rows(): array {
		$rows = [];
		foreach ( MigrationRegistry::list_migrations() as $migration ) {
			$summary = MigrationRegistry::summarize_site_jobs( (int) $migration->id );

			$report_pairs = [];
			foreach ( MigrationRegistry::get_site_jobs_for_migration( (int) $migration->id ) as $site_job ) {
				$report_post_id = AuditReport::get_report_post_id_for_site_job( (int) $site_job->id );
				if ( null !== $report_post_id ) {
					$report_pairs[] = $site_job->id . ':' . $report_post_id;
				}
			}

			$rows[] = [
				'migration_id'    => (int) $migration->id,
				'status'          => $migration->status,
				'source_url'      => $migration->source_url,
				'source_domains'  => $summary['source_domains'] ? implode( ', ', $summary['source_domains'] ) : '(none)',
				'dest_domains'    => $summary['dest_domains'] ? implode( ', ', $summary['dest_domains'] ) : '(unassigned)',
				'last_sync'       => $summary['last_sync'] ?? 'Never',
				'report_post_ids' => $report_pairs ? implode( ', ', $report_pairs ) : '(none)',
			];
		}

		return $rows;
	}
}
