<?php

namespace HBMigrator;

class PipelineController {

	private const BASE_DELAY     = 60;
	private const MAX_RETRIES_CAP = 10;

	/**
	 * Called by destination batch callbacks on exception.
	 * Retries with exponential backoff; on exhaustion marks the site_job failed.
	 *
	 * @param string     $action    AS action hook name.
	 * @param array      $args      Original action args (must include 'attempt' key).
	 * @param \Throwable $e         The caught exception.
	 * @param int|null   $site_job_id When provided, marks the job failed on exhaustion.
	 */
	public static function handle_batch_failure(
		string $action,
		array $args,
		\Throwable $e,
		?int $site_job_id = null
	): void {
		$attempt     = (int) ( $args['attempt'] ?? 0 );
		$max_retries = min(
			(int) apply_filters( 'hbm_max_retries', 3 ),
			self::MAX_RETRIES_CAP
		);

		// Non-retryable source errors (e.g. 400, 401, 404) should not burn the retry budget.
		$retryable = ! ( $e instanceof SourceClientException ) || $e->retryable;

		if ( $retryable && $attempt < $max_retries ) {
			$delay         = self::BASE_DELAY * (int) pow( 2, $attempt );
			$args['attempt'] = $attempt + 1;
			as_schedule_single_action( time() + $delay, $action, $args, 'hb-migrator' );
			return;
		}

		if ( null !== $site_job_id ) {
			MigrationRegistry::update_site_job( $site_job_id, [
				'status'        => 'failed',
				'error_message' => $e->getMessage(),
			] );
		}
	}
}
