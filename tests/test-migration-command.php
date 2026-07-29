<?php
/**
 * Tests for Cli\MigrationCommand — specifically build_rows(), the row-building logic
 * split out from list()'s WP-CLI rendering call. list() itself calls
 * \WP_CLI\Utils\format_items()/\WP_CLI\Utils\get_flag_value() directly, which requires a
 * real WP-CLI runtime this PHPUnit environment does not provide — see
 * tests/test-migration-key-command.php's identical rationale for MigrationKeyCommand's
 * get()/update()/delete(). list() itself is exercised via a live `wp hbm migration list`
 * smoke test instead, not by this suite.
 *
 * Covers R1 (docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, "U1. CLI
 * report-post-id lookup"): build_rows()'s new report_post_ids column.
 */

use HBMigrator\Cli\MigrationCommand;
use HBMigrator\Destination\AuditReport;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_Migration_Command extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
	}

	private function make_site_job( int $migration_id, string $domain, string $path ): int {
		return MigrationRegistry::create_site_job( $migration_id, 1, $domain, 'https://93.184.216.34', '', $path );
	}

	private function find_row( array $rows, int $migration_id ): ?array {
		foreach ( $rows as $row ) {
			if ( $migration_id === $row['migration_id'] ) {
				return $row;
			}
		}
		return null;
	}

	public function test_build_rows_omits_report_post_ids_for_migration_with_no_reports(): void {
		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		$this->make_site_job( $mid, 'a.example.com', '/a/' );

		$row = $this->find_row( MigrationCommand::build_rows(), $mid );

		$this->assertNotNull( $row );
		$this->assertSame( '(none)', $row['report_post_ids'] );
	}

	public function test_build_rows_lists_job_id_post_id_pairs_for_site_jobs_with_reports(): void {
		$mid  = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		$jid1 = $this->make_site_job( $mid, 'a.example.com', '/a/' );
		$jid2 = $this->make_site_job( $mid, 'b.example.com', '/b/' );
		$jid3 = $this->make_site_job( $mid, 'c.example.com', '/c/' );

		// Only two of the three site jobs ever had a trail-worthy event.
		$post_id_1 = AuditReport::get_or_create_for_site_job( $jid1 );
		$post_id_2 = AuditReport::get_or_create_for_site_job( $jid2 );
		// $jid3 deliberately gets no report.

		$row = $this->find_row( MigrationCommand::build_rows(), $mid );

		$this->assertNotNull( $row );
		$this->assertSame(
			"{$jid1}:{$post_id_1}, {$jid2}:{$post_id_2}",
			$row['report_post_ids'],
			'Only site jobs with an existing report should appear, in site-job order, and the job with no report must be omitted entirely.'
		);
		$this->assertStringNotContainsString( (string) $jid3, $row['report_post_ids'] );
	}

	public function test_build_rows_creates_zero_new_report_posts_as_a_side_effect(): void {
		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		$this->make_site_job( $mid, 'a.example.com', '/a/' );
		$this->make_site_job( $mid, 'b.example.com', '/b/' );

		$count_before = self::count_report_posts();
		MigrationCommand::build_rows();
		$count_after = self::count_report_posts();

		$this->assertSame( $count_before, $count_after, 'Building migration list rows must never create a report post as a side effect.' );
	}

	public function test_build_rows_still_includes_existing_columns(): void {
		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		$this->make_site_job( $mid, 'a.example.com', '/a/' );

		$row = $this->find_row( MigrationCommand::build_rows(), $mid );

		$this->assertNotNull( $row );
		foreach ( [ 'migration_id', 'status', 'source_url', 'source_domains', 'dest_domains', 'last_sync', 'report_post_ids' ] as $column ) {
			$this->assertArrayHasKey( $column, $row );
		}
	}

	private static function count_report_posts(): int {
		switch_to_blog( get_main_site_id() );
		$count = count( get_posts( [
			'post_type'   => AuditReport::POST_TYPE,
			'post_status' => 'private',
			'numberposts' => -1,
			'fields'      => 'ids',
		] ) );
		restore_current_blog();
		return $count;
	}
}
