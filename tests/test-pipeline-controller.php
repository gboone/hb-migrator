<?php
/**
 * Tests for PipelineController (v2 — handles batch failure and retry for destination jobs).
 */

use HBMigrator\PipelineController;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_Pipeline_Controller extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
	}

	public function test_handle_batch_failure_reschedules_on_first_attempt(): void {
		$mid = MigrationRegistry::create_migration( 'https://src.example.com', 'key', null );
		$jid = MigrationRegistry::create_site_job( $mid, 4, 'src.example.com', 'https://src.example.com', '', '/src.example.com/' );

		$e = new \RuntimeException( 'Temporary failure' );
		PipelineController::handle_batch_failure(
			'hbm_import_posts',
			[ 'site_job_id' => $jid, 'last_id' => 0, 'attempt' => 0 ],
			$e,
			$jid
		);

		$job = MigrationRegistry::get_site_job( $jid );
		// Should NOT be failed — should have been rescheduled.
		$this->assertNotSame( 'failed', $job->status );
	}

	public function test_handle_batch_failure_marks_failed_after_exhaustion(): void {
		$mid = MigrationRegistry::create_migration( 'https://src.example.com', 'key', null );
		$jid = MigrationRegistry::create_site_job( $mid, 4, 'src.example.com', 'https://src.example.com', '', '/src.example.com/' );

		add_filter( 'hbm_max_retries', fn() => 1 );

		$e = new \RuntimeException( 'Persistent failure' );
		PipelineController::handle_batch_failure(
			'hbm_import_posts',
			[ 'site_job_id' => $jid, 'last_id' => 0, 'attempt' => 1 ],
			$e,
			$jid
		);

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( 'failed', $job->status );
		$this->assertStringContainsString( 'Persistent failure', $job->error_message );

		remove_all_filters( 'hbm_max_retries' );
	}

	public function test_handle_batch_failure_without_site_job_id_does_not_crash(): void {
		$e = new \RuntimeException( 'Error' );
		// Should not throw.
		PipelineController::handle_batch_failure(
			'hbm_import_network_users',
			[ 'migration_id' => 99, 'offset' => 0, 'attempt' => 5 ],
			$e
		);
		$this->assertTrue( true );
	}
}
