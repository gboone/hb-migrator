<?php
/**
 * Tests for PipelineController.
 */

use HBMigrator\Checkpoint;
use HBMigrator\PipelineController;
use HBMigrator\QueueTable;

class Test_Pipeline_Controller extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
		Checkpoint::reset_all();
	}

	public function test_start_export_initializes_three_stages_as_running(): void {
		PipelineController::start_export();
		foreach ( [ 'sql', 'wxr', 'media' ] as $stage ) {
			$row = Checkpoint::get_stage( $stage );
			$this->assertNotNull( $row, "Stage $stage should exist" );
			$this->assertSame( 'running', $row->status );
		}
	}

	public function test_start_export_returns_false_when_already_running(): void {
		PipelineController::start_export();
		$result = PipelineController::start_export();
		$this->assertFalse( $result );
	}

	public function test_stage_complete_marks_status(): void {
		Checkpoint::initialize_stages();
		PipelineController::stage_complete( 'sql' );
		$row = Checkpoint::get_stage( 'sql' );
		$this->assertSame( 'complete', $row->status );
	}

	public function test_retry_stage_with_invalid_stage_name_returns_false(): void {
		$result = PipelineController::retry_stage( 'invalid_stage' );
		$this->assertFalse( $result );
	}

	public function test_retry_stage_with_allowlisted_names(): void {
		foreach ( [ 'sql', 'wxr', 'media' ] as $stage ) {
			Checkpoint::initialize_stages();
			Checkpoint::mark_stage_failed( $stage, 'test error' );
			$result = PipelineController::retry_stage( $stage );
			$this->assertTrue( $result, "retry_stage('$stage') should return true" );
			$row = Checkpoint::get_stage( $stage );
			$this->assertSame( 'running', $row->status );
			$this->assertSame( 0, (int) $row->attempt_count );
			Checkpoint::reset_all();
		}
	}

	public function test_retry_stage_returns_false_for_non_failed_stage(): void {
		Checkpoint::initialize_stages();
		// Stage is 'pending', not 'failed'.
		$result = PipelineController::retry_stage( 'sql' );
		$this->assertFalse( $result );
	}

	public function test_reset_wipes_stages(): void {
		PipelineController::start_export();
		PipelineController::reset();
		$this->assertCount( 0, Checkpoint::get_all_stages() );
	}

	public function test_handle_batch_failure_marks_failed_after_max_retries(): void {
		Checkpoint::initialize_stages();
		$error = new \RuntimeException( 'Disk full' );
		// Simulate 3 retries already exhausted (mock max via filter).
		add_filter( 'hbm_max_retries', fn() => 1 );
		// First failure.
		PipelineController::handle_batch_failure( 'sql', $error, [ 0, 0, 0 ] );
		// Second failure (attempt_count = 2 now, exceeds max_retries = 1).
		PipelineController::handle_batch_failure( 'sql', $error, [ 0, 0, 0 ] );
		$row = Checkpoint::get_stage( 'sql' );
		$this->assertSame( 'failed', $row->status );
		$this->assertStringContainsString( 'Disk full', $row->error_message );
		remove_all_filters( 'hbm_max_retries' );
	}

	public function test_get_progress_returns_all_stages(): void {
		Checkpoint::initialize_stages();
		$progress = PipelineController::get_progress();
		$this->assertArrayHasKey( 'sql', $progress );
		$this->assertArrayHasKey( 'wxr', $progress );
		$this->assertArrayHasKey( 'media', $progress );
	}

	public function test_retry_stage_rejects_injection_attempts(): void {
		$malicious = "sql'; DROP TABLE hbm_queue;--";
		$result    = PipelineController::retry_stage( $malicious );
		$this->assertFalse( $result );
	}
}
