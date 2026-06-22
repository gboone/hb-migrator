<?php

namespace HBMigrator;

class PipelineController {

	private const VALID_STAGES = [ 'sql', 'wxr', 'media' ];

	/** Number of seconds between retry attempts (doubles each attempt). */
	private const BASE_DELAY_SECONDS = 60;

	/** Absolute ceiling applied before the filter so callers can't set 0 retries. */
	private const MAX_RETRIES_CEILING = 10;

	public static function start_export(): bool {
		// Ensure schema exists (safe to call on every invocation).
		QueueTable::maybe_create_or_upgrade();

		// Bail if a previous export is still running.
		$stages = Checkpoint::get_all_stages();
		foreach ( $stages as $row ) {
			if ( in_array( $row->status, [ 'running', 'pending' ], true ) ) {
				return false;
			}
		}

		Checkpoint::reset_all();
		Checkpoint::initialize_stages();

		// Kick off the three stages concurrently.
		as_enqueue_async_action( 'hbm_export_sql_batch', [ 0, 0, 0 ], 'hb-migrator' );
		as_enqueue_async_action( 'hbm_export_wxr_batch', [ 0, 0 ], 'hb-migrator' );
		as_enqueue_async_action( 'hbm_export_media_batch', [ 0, 0, 0 ], 'hb-migrator' );

		foreach ( self::VALID_STAGES as $stage ) {
			Checkpoint::set_status( $stage, 'running' );
		}

		return true;
	}

	/**
	 * Called by a batch callback when it catches an exception.
	 *
	 * @param string     $stage     One of 'sql', 'wxr', 'media'.
	 * @param \Throwable $throwable The caught exception.
	 * @param array      $args      The original AS action args (for retry dispatch).
	 */
	public static function handle_batch_failure( string $stage, \Throwable $throwable, array $args ): void {
		Checkpoint::increment_attempt( $stage );
		$row          = Checkpoint::get_stage( $stage );
		$attempt      = $row ? (int) $row->attempt_count : 1;
		$max_retries  = min(
			(int) apply_filters( 'hbm_max_retries', 3 ),
			self::MAX_RETRIES_CEILING
		);

		if ( $attempt > $max_retries ) {
			Checkpoint::mark_stage_failed( $stage, $throwable->getMessage() );
			return;
		}

		// Exponential backoff: 60 s, 120 s, 240 s, ...
		$delay = self::BASE_DELAY_SECONDS * (int) pow( 2, $attempt - 1 );
		$hook  = 'hbm_export_' . $stage . '_batch';
		if ( 'media' !== $stage ) {
			$hook = 'hbm_export_' . $stage . '_batch';
		}

		as_schedule_single_action( time() + $delay, $hook, $args, 'hb-migrator' );
		Checkpoint::set_status( $stage, 'running' );
	}

	/**
	 * Called by each exporter when its stage produces no more work.
	 */
	public static function stage_complete( string $stage ): void {
		Checkpoint::mark_stage_complete( $stage );
	}

	/**
	 * Re-enqueue a failed stage for retry. Validates the stage name against an
	 * allowlist before touching the database or AS (resolves doc-review F9).
	 *
	 * @param string $stage Must be one of 'sql', 'wxr', 'media'.
	 * @return bool True if the retry was enqueued, false if the stage name is invalid.
	 */
	public static function retry_stage( string $stage ): bool {
		if ( ! in_array( $stage, self::VALID_STAGES, true ) ) {
			return false;
		}

		$row = Checkpoint::get_stage( $stage );
		if ( null === $row || 'failed' !== $row->status ) {
			return false;
		}

		Checkpoint::set_status( $stage, 'running' );
		// Reset attempt count so the retry gets a fresh backoff cycle.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'hbm_queue',
			[ 'attempt_count' => 0, 'error_message' => null ],
			[ 'stage' => $stage ]
		);

		// Re-dispatch at the stage's last saved offsets so it resumes mid-table.
		$batch_offset = (int) $row->batch_offset;
		$row_offset   = (int) $row->row_offset;

		switch ( $stage ) {
			case 'sql':
				as_enqueue_async_action( 'hbm_export_sql_batch', [ $batch_offset, $row_offset, 0 ], 'hb-migrator' );
				break;
			case 'wxr':
				as_enqueue_async_action( 'hbm_export_wxr_batch', [ $batch_offset, 0 ], 'hb-migrator' );
				break;
			case 'media':
				as_enqueue_async_action( 'hbm_export_media_batch', [ $batch_offset, 0, 0 ], 'hb-migrator' );
				break;
		}

		return true;
	}

	/**
	 * Cancel all pending AS actions and wipe all export state and artifacts.
	 */
	public static function reset(): void {
		as_unschedule_all_actions( '', [], 'hb-migrator' );
		Checkpoint::reset_all();
		ArtifactManager::delete_all_artifacts();
	}

	/**
	 * Return a summary of all stage states for progress display.
	 *
	 * @return array<string, array{status: string, batch_offset: int, total_items: int, attempt_count: int, error_message: string|null}>
	 */
	public static function get_progress(): array {
		$stages = Checkpoint::get_all_stages();
		$result = [];
		foreach ( $stages as $row ) {
			$result[ $row->stage ] = [
				'status'        => $row->status,
				'batch_offset'  => (int) $row->batch_offset,
				'total_items'   => (int) $row->total_items,
				'attempt_count' => (int) $row->attempt_count,
				'error_message' => $row->error_message,
			];
		}
		return $result;
	}
}
