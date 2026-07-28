<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\PipelineController;

class SearchReplace {

	// Authoritative skip list for options. Matches OptionReader::SKIP so both
	// sides exclude the same names (source never sends them; destination never replaces them).
	private const SKIP_OPTION_NAMES = [ 'siteurl', 'home' ];

	// How many seconds to spend per AS action before checkpointing.
	// VIP AS runner has no hard per-action wall-clock kill, but 50 seconds leaves
	// headroom for the rest of process() and avoids unexpected timeout behavior.
	private const TIME_LIMIT = 50.0;

	// Total number of phases (posts×4 columns, postmeta, options).
	private const PHASE_COUNT = 6;

	/**
	 * Processes one phase of the search-replace pass.
	 *
	 * @param int $site_job_id
	 * @param int $attempt      Retry counter managed by PipelineController.
	 * @param int $phase        0–5: which table/column to work on this action.
	 * @param int $last_pk      Keyset cursor — resume from this pk within the phase.
	 */
	public static function process( int $site_job_id, int $attempt, int $phase = 0, int $last_pk = 0 ): void {
		try {
			$job = MigrationRegistry::get_site_job( $site_job_id );
			if ( ! $job || ! $job->dest_blog_id ) {
				return;
			}

			$migration = MigrationRegistry::get_migration( (int) $job->migration_id );
			if ( ! $migration || 'cancelled' === $migration->status ) {
				return;
			}

			MigrationRegistry::update_site_job( $site_job_id, [ 'status' => 'running', 'current_stage' => 'search_replace', 'error_message' => null ] );

			switch_to_blog( (int) $job->dest_blog_id );

			$dest_siteurl    = get_option( 'siteurl' );
			$dest_upload_url = trailingslashit( wp_upload_dir()['baseurl'] );
			$source_siteurl  = rtrim( $job->source_siteurl, '/' );
			$source_upload   = rtrim( $job->source_upload_url, '/' );

			restore_current_blog();

			// Filter on the KEY (source URL) — replacing an empty source string would corrupt all content.
			$replacements = array_filter( [
				$source_siteurl => rtrim( $dest_siteurl, '/' ),
				$source_upload  => rtrim( $dest_upload_url, '/' ),
			], fn( $key ) => ! empty( $key ), ARRAY_FILTER_USE_KEY );

			if ( empty( $replacements ) ) {
				// No URL replacements — run postmeta ID remap and finalize.
				self::finalize( $site_job_id, (int) $job->migration_id, (int) $job->dest_blog_id );
				return;
			}

			$checkpoint = self::run_phase(
				(int) $job->dest_blog_id,
				$replacements,
				$phase,
				$last_pk
			);

			if ( null !== $checkpoint ) {
				// Time budget exhausted mid-phase — dispatch a continuation from the checkpoint.
				as_enqueue_async_action(
					'hbm_search_replace',
					[ 'site_job_id' => $site_job_id, 'attempt' => 0, 'phase' => $phase, 'last_pk' => $checkpoint ],
					'hb-migrator'
				);
				return;
			}

			$next_phase = $phase + 1;
			if ( $next_phase < self::PHASE_COUNT ) {
				// Phase complete — dispatch the next phase.
				as_enqueue_async_action(
					'hbm_search_replace',
					[ 'site_job_id' => $site_job_id, 'attempt' => 0, 'phase' => $next_phase, 'last_pk' => 0 ],
					'hb-migrator'
				);
				return;
			}

			// All phases done.
			self::finalize( $site_job_id, (int) $job->migration_id, (int) $job->dest_blog_id );

		} catch ( \Throwable $e ) {
			if ( isset( $job ) && $job ) {
				restore_current_blog();
			}
			PipelineController::handle_batch_failure(
				'hbm_search_replace',
				[ 'site_job_id' => $site_job_id, 'attempt' => $attempt, 'phase' => $phase, 'last_pk' => $last_pk ],
				$e,
				$site_job_id
			);
		}
	}

	private static function finalize( int $site_job_id, int $migration_id, int $blog_id ): void {
		self::remap_postmeta_ids( $site_job_id, $blog_id );
		MigrationRegistry::update_site_job( $site_job_id, [
			'status'        => 'complete',
			'current_stage' => null,
		] );

		// U7: enqueue the audit comparator's self-chaining entry point rather than calling it
		// synchronously — comparison can take arbitrarily long across many self-chained batches
		// (see AuditComparator::process()) and must never delay finalize() itself completing.
		as_enqueue_async_action( 'hbm_audit_compare', [ 'site_job_id' => $site_job_id, 'last_pk' => 0 ], 'hb-migrator' );

		// complete_migration() uses a NOT EXISTS subquery to atomically check that all
		// site jobs are complete before updating the migration row — no separate read needed.
		if ( MigrationRegistry::complete_migration( $migration_id ) ) {
			self::maybe_send_notification( $migration_id );
		}
	}

	/**
	 * Processes one phase within the destination subsite blog context.
	 *
	 * @param int   $blog_id
	 * @param array $replacements
	 * @param int   $phase      0=posts.post_content, 1=post_excerpt, 2=post_title,
	 *                          3=guid, 4=postmeta.meta_value, 5=options.option_value
	 * @param int   $from_pk    Resume keyset cursor for the phase.
	 * @return int|null  null = phase complete; int = last pk processed (time budget exceeded).
	 */
	private static function run_phase( int $blog_id, array $replacements, int $phase, int $from_pk ): ?int {
		global $wpdb;
		switch_to_blog( $blog_id );
		$started = microtime( true );

		switch ( $phase ) {
			case 0:
				$result = self::replace_in_column( $wpdb->posts, 'post_content', $replacements, $from_pk, $started );
				break;
			case 1:
				$result = self::replace_in_column( $wpdb->posts, 'post_excerpt', $replacements, $from_pk, $started );
				break;
			case 2:
				$result = self::replace_in_column( $wpdb->posts, 'post_title', $replacements, $from_pk, $started );
				break;
			case 3:
				$result = self::replace_in_column( $wpdb->posts, 'guid', $replacements, $from_pk, $started );
				break;
			case 4:
				$result = self::replace_in_column( $wpdb->postmeta, 'meta_value', $replacements, $from_pk, $started );
				break;
			case 5:
				$result = self::replace_in_options( $wpdb->options, $replacements, $from_pk, $started );
				break;
			default:
				$result = null;
		}

		restore_current_blog();
		return $result;
	}

	/**
	 * Replaces strings in a single non-options column with keyset pagination.
	 *
	 * @return int|null  null = table exhausted; int = last pk (time budget exceeded, checkpoint here).
	 */
	private static function replace_in_column( string $table, string $col, array $replacements, int $from_pk, float $started ): ?int {
		global $wpdb;

		$is_postmeta = false !== strpos( $table, 'postmeta' );
		$pk_col      = $is_postmeta ? 'meta_id' : 'ID';
		// cache_col identifies which post/comment cache entry a changed row belongs to —
		// same as pk_col for posts/comments, but postmeta's own pk (meta_id) isn't a cache
		// key on its own, so its owning post_id is selected instead (see replace_rows()).
		$cache_col = $is_postmeta ? 'post_id' : $pk_col;
		$last_pk   = $from_pk;
		$batch     = 200;

		while ( true ) {
			// Keyset pagination — stable under concurrent writes, O(n) instead of O(n²).
			$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT {$pk_col} AS pk, `{$cache_col}` AS cache_id, `{$col}` AS val FROM `{$table}` WHERE {$pk_col} > %d ORDER BY {$pk_col} ASC LIMIT %d",
				$last_pk,
				$batch
			), ARRAY_A );

			if ( empty( $rows ) ) {
				return null; // Phase complete.
			}

			// $rows is non-empty here, so replace_rows() always returns a real pk.
			$last_pk = self::replace_rows( $table, $col, $pk_col, $rows, $replacements );

			if ( ( microtime( true ) - $started ) > self::TIME_LIMIT ) {
				return $last_pk; // Budget exceeded — checkpoint here.
			}
		}
	}

	/**
	 * Single source of truth for the "replace each fetched row's value via safe_replace(), then
	 * UPDATE only if it actually changed" inner loop shared by replace_in_column() (whole-table
	 * keyset scan) and replace_in_column_scoped() (specific ID list). Previously duplicated
	 * verbatim in both methods — a deliberate choice to keep the scoped-mode addition strictly
	 * additive, later flagged as a duplication-drift risk. This method is now the only place
	 * that logic lives; both callers pass their own already-fetched $rows.
	 *
	 * @param string $table
	 * @param string $col      Column to update.
	 * @param string $pk_col   Primary-key column used for the UPDATE ... WHERE clause.
	 * @param array  $rows     Already-fetched rows, each an ARRAY_A row with 'pk', 'cache_id',
	 *                         and 'val' keys — 'cache_id' identifies the post/comment cache
	 *                         entry to invalidate (see replace_in_column()/
	 *                         replace_in_column_scoped()).
	 * @param array  $replacements
	 * @return int  The pk of the last row processed (0 if $rows is empty).
	 */
	private static function replace_rows( string $table, string $col, string $pk_col, array $rows, array $replacements ): int {
		global $wpdb;

		$last_pk = 0;
		foreach ( $rows as $row ) {
			$original = $row['val'];
			$replaced = self::safe_replace( $original, $replacements );
			if ( $replaced !== $original ) {
				$wpdb->update( $table, [ $col => $replaced ], [ $pk_col => $row['pk'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				// This direct write bypasses wp_update_post()/wp_update_comment()'s own cache
				// cleanup, so a cached WP_Post/WP_Comment object (or, for postmeta, the
				// post's cached meta array) would otherwise still hold the pre-replacement
				// value — the same object-cache-staleness class of bug fixed for
				// PostImporter/CommentImporter's own direct writes.
				if ( $wpdb->comments === $table ) {
					clean_comment_cache( (int) $row['cache_id'] );
				} else {
					clean_post_cache( (int) $row['cache_id'] );
				}
			}
			$last_pk = (int) $row['pk'];
		}

		return $last_pk;
	}

	/**
	 * Replaces strings in the options table with keyset pagination.
	 *
	 * @return int|null  null = table exhausted; int = last option_id (time budget exceeded).
	 */
	private static function replace_in_options( string $table, array $replacements, int $from_pk, float $started ): ?int {
		global $wpdb;

		$last_pk = $from_pk;
		$batch   = 200;

		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT option_id AS pk, option_name, option_value AS val FROM `{$table}`
				  WHERE option_id > %d
				    AND option_name NOT IN ('siteurl','home')
				  ORDER BY option_id ASC
				  LIMIT %d",
				$last_pk,
				$batch
			), ARRAY_A );

			if ( empty( $rows ) ) {
				return null; // Phase complete.
			}

			foreach ( $rows as $row ) {
				if ( in_array( $row['option_name'], self::SKIP_OPTION_NAMES, true ) ) {
					$last_pk = (int) $row['pk'];
					continue;
				}
				$original = $row['val'];
				$replaced = self::safe_replace( $original, $replacements );
				if ( $replaced !== $original ) {
					$wpdb->update( $table, [ 'option_value' => $replaced ], [ 'option_id' => $row['pk'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
				$last_pk = (int) $row['pk'];
			}

			if ( ( microtime( true ) - $started ) > self::TIME_LIMIT ) {
				return $last_pk; // Budget exceeded — checkpoint here.
			}
		}
	}

	/**
	 * Serialization-aware, binary-safe string replacement.
	 *
	 * @param mixed  $value        The value to search within.
	 * @param array  $replacements Map of old => new strings.
	 * @return mixed
	 */
	public static function safe_replace( $value, array $replacements ) {
		if ( is_array( $value ) ) {
			$result = [];
			foreach ( $value as $k => $v ) {
				$new_key            = is_string( $k ) ? str_replace( array_keys( $replacements ), array_values( $replacements ), $k ) : $k;
				$result[ $new_key ] = self::safe_replace( $v, $replacements );
			}
			return $result;
		}

		if ( $value instanceof \__PHP_Incomplete_Class ) {
			// A serialized object for a class not loaded in this process (allowed_classes:false
			// above turns it into this rather than instantiating the real class — the object
			// injection guard). PHP forbids writing properties directly on an incomplete-class
			// object, so properties can't be replaced in place. Instead, hand-build a fresh
			// serialized representation with the replaced property values and unserialize it
			// again — producing a new, correctly-typed __PHP_Incomplete_Class instance (same
			// external class name) that composes correctly whether this call is the top-level
			// replacement or nested inside an outer array/object being serialized by the
			// caller.
			$props      = (array) $value;
			$class_name = (string) ( $props['__PHP_Incomplete_Class_Name'] ?? '' );
			unset( $props['__PHP_Incomplete_Class_Name'] );

			$body = '';
			foreach ( $props as $k => $v ) {
				$body .= serialize( (string) $k ) . serialize( self::safe_replace( $v, $replacements ) );
			}
			$rebuilt = 'O:' . strlen( $class_name ) . ':"' . $class_name . '":' . count( $props ) . ':{' . $body . '}';
			return unserialize( $rebuilt, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		// Skip binary data — str_replace on non-UTF-8 bytes corrupts EXIF/binary meta.
		if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
			return $value;
		}

		if ( is_serialized( $value ) ) {
			// Use allowed_classes:false to prevent object instantiation (PHP object injection).
			// Incomplete-class objects round-trip correctly through serialize/unserialize.
			$data = unserialize( $value, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			if ( false !== $data || 'b:0;' === $value ) {
				$replaced = self::safe_replace( $data, $replacements );
				return serialize( $replaced );
			}
		}

		foreach ( $replacements as $old => $new ) {
			$value = str_replace( $old, $new, $value );
		}

		return $value;
	}

	/**
	 * U6: scoped mode for sync passes. Runs the same safe_replace() serialization-aware
	 * string replacement and hbm_id_map-joined _thumbnail_id remap as the whole-table mode
	 * above, but filtered to exactly the destination post/attachment/comment rows a sync
	 * pass just touched (dest IDs — already resolved from source IDs via IdMap by the
	 * caller) instead of a keyset scan across the full posts/postmeta/comments tables.
	 *
	 * Deliberately does NOT touch the options table — options are excluded from every sync
	 * pass (plan "Key Technical Decisions": OptionImporter wholesale-overwrites destination
	 * values, and re-running it would clobber destination-side QA changes), so there is no
	 * scoped counterpart to replace_in_options() here.
	 *
	 * No time-budget checkpointing (unlike run_phase()'s TIME_LIMIT/continuation dance):
	 * every caller (SyncSearchReplaceStage) passes IDs already bounded by a sync stage's own
	 * per-pass row budget (<=100 posts, <=50 media, <=100 comments — see
	 * SyncStageInterface), so a single call here always completes in well under
	 * self::TIME_LIMIT. The whole-table mode above (run_phase(), used at initial-migration
	 * finalize) is completely unchanged by this addition — this method is strictly additive.
	 *
	 * @param int   $site_job_id
	 * @param int[] $post_ids    Destination post IDs (posts and/or attachments — attachments
	 *                           are `wp_posts` rows too) touched by this sync pass.
	 * @param int[] $comment_ids Destination comment IDs touched by this sync pass.
	 */
	public static function replace_scoped( int $site_job_id, array $post_ids, array $comment_ids = [] ): void {
		global $wpdb;

		$post_ids    = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
		$comment_ids = array_values( array_unique( array_map( 'intval', $comment_ids ) ) );

		if ( empty( $post_ids ) && empty( $comment_ids ) ) {
			// Fast no-op — nothing touched this pass (U6 test scenario: a zero-row scoped
			// pass must not run a query, let alone fall back to a full-table scan).
			return;
		}

		$job = MigrationRegistry::get_site_job( $site_job_id );
		if ( ! $job || ! $job->dest_blog_id ) {
			return;
		}

		switch_to_blog( (int) $job->dest_blog_id );
		$dest_siteurl    = get_option( 'siteurl' );
		$dest_upload_url = trailingslashit( wp_upload_dir()['baseurl'] );
		restore_current_blog();

		$source_siteurl = rtrim( $job->source_siteurl, '/' );
		$source_upload  = rtrim( $job->source_upload_url, '/' );

		// Filter on the KEY (source URL) — replacing an empty source string would corrupt
		// all content. Mirrors process()'s own $replacements construction exactly (kept as a
		// separate, small duplication rather than a shared extraction, so the existing
		// whole-table process()/run_phase() path above is not touched by this addition at
		// all — see this method's docblock).
		$replacements = array_filter( [
			$source_siteurl => rtrim( $dest_siteurl, '/' ),
			$source_upload  => rtrim( $dest_upload_url, '/' ),
		], fn( $key ) => ! empty( $key ), ARRAY_FILTER_USE_KEY );

		switch_to_blog( (int) $job->dest_blog_id );
		try {
			if ( ! empty( $post_ids ) ) {
				if ( ! empty( $replacements ) ) {
					self::replace_in_column_scoped( $wpdb->posts, 'post_content', $replacements, $post_ids, 'ID', 'ID' );
					self::replace_in_column_scoped( $wpdb->posts, 'post_excerpt', $replacements, $post_ids, 'ID', 'ID' );
					self::replace_in_column_scoped( $wpdb->posts, 'post_title', $replacements, $post_ids, 'ID', 'ID' );
					self::replace_in_column_scoped( $wpdb->posts, 'guid', $replacements, $post_ids, 'ID', 'ID' );
					self::replace_in_column_scoped( $wpdb->postmeta, 'meta_value', $replacements, $post_ids, 'post_id', 'meta_id' );
				}
				self::remap_postmeta_ids_scoped( $site_job_id, $post_ids );
			}

			if ( ! empty( $comment_ids ) && ! empty( $replacements ) ) {
				self::replace_in_column_scoped( $wpdb->comments, 'comment_content', $replacements, $comment_ids, 'comment_ID', 'comment_ID' );
			}
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Scoped counterpart to replace_in_column()/replace_in_options() — filters to specific
	 * rows via `WHERE {$filter_col} IN (...)` instead of the whole-table keyset scan those
	 * methods run. Reuses safe_replace() as-is. No pagination or time-budget checkpoint: the
	 * caller (replace_scoped()) only ever passes an ID set already bounded by a sync stage's
	 * own row budget, so this always finishes in one pass.
	 *
	 * @param string $table
	 * @param string $col         Column to search/replace within.
	 * @param array  $replacements
	 * @param int[]  $ids         Row IDs to filter to.
	 * @param string $filter_col  Column the IN() clause filters on (e.g. `post_id` for
	 *                            postmeta, since postmeta's own PK is `meta_id` — a post can
	 *                            have many postmeta rows).
	 * @param string $pk_col      Primary-key column used for the UPDATE ... WHERE clause.
	 */
	private static function replace_in_column_scoped( string $table, string $col, array $replacements, array $ids, string $filter_col, string $pk_col ): void {
		global $wpdb;

		if ( empty( $ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// filter_col also identifies the post/comment cache entry each row belongs to (see
		// replace_rows()) — for posts/comments it's the same column as pk_col; for postmeta
		// it's post_id, which pk_col (meta_id) alone can't provide.
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT `{$pk_col}` AS pk, `{$filter_col}` AS cache_id, `{$col}` AS val FROM `{$table}` WHERE `{$filter_col}` IN ({$placeholders})",
			$ids
		), ARRAY_A );

		if ( empty( $rows ) ) {
			return;
		}

		self::replace_rows( $table, $col, $pk_col, $rows, $replacements );
	}

	/**
	 * Scoped counterpart to remap_postmeta_ids() — same _thumbnail_id UPDATE...JOIN shape
	 * against `hbm_id_map`, filtered to `pm.post_id IN (...)` so a sync pass only rescans the
	 * postmeta rows belonging to the posts it just touched, not the whole postmeta table.
	 * Assumes the caller has already switched to the destination blog (mirrors
	 * replace_in_column_scoped(), unlike remap_postmeta_ids() which switches/restores itself
	 * since it's called directly from finalize(), outside any existing switch_to_blog bracket).
	 *
	 * The actual UPDATE...JOIN SQL now lives solely in run_thumbnail_remap() — see that
	 * method's docblock re: the planned nav_menu_item remap addition.
	 *
	 * @param int   $site_job_id
	 * @param int[] $post_ids Destination post IDs to scope the JOIN to.
	 */
	private static function remap_postmeta_ids_scoped( int $site_job_id, array $post_ids ): void {
		if ( empty( $post_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		self::run_thumbnail_remap( $site_job_id, " AND pm.post_id IN ({$placeholders})", $post_ids );
	}

	/**
	 * Rewrites _thumbnail_id postmeta values from source attachment IDs to destination IDs.
	 * Runs as the last step before marking the site job complete, when the full IdMap is available.
	 */
	private static function remap_postmeta_ids( int $site_job_id, int $blog_id ): void {
		switch_to_blog( $blog_id );
		self::run_thumbnail_remap( $site_job_id );
		restore_current_blog();
	}

	/**
	 * Single source of truth for the _thumbnail_id postmeta UPDATE...JOIN shape against
	 * `hbm_id_map`, shared by remap_postmeta_ids() (whole-table, no extra filter) and
	 * remap_postmeta_ids_scoped() (adds a `pm.post_id IN (...)` filter). Previously this SQL
	 * shape was duplicated verbatim in both methods — a deliberate choice to keep the
	 * scoped-mode addition strictly additive, later flagged as a duplication-drift risk. This
	 * method is now the only place the JOIN/remap logic lives; a future change (e.g. the
	 * sibling post-type-migration-audit plan's planned nav_menu_item remap addition alongside
	 * _thumbnail_id — see docs/plans/2026-07-13-001-fix-post-type-migration-audit-plan.md) only
	 * needs to happen here to apply to both the whole-table and scoped callers.
	 *
	 * @param int    $site_job_id
	 * @param string $extra_where  Additional WHERE-clause fragment, ANDed after the base
	 *                             `pm.meta_key = '_thumbnail_id'` condition. Must not embed raw
	 *                             values — any placeholders in it are bound via $extra_params.
	 *                             Empty string (default) = no additional filter (whole-table).
	 * @param array  $extra_params Additional wpdb::prepare() params consumed by $extra_where's
	 *                             placeholders, appended after $site_job_id in order.
	 */
	private static function run_thumbnail_remap( int $site_job_id, string $extra_where = '', array $extra_params = [] ): void {
		global $wpdb;

		$id_map_table = $wpdb->base_prefix . 'hbm_id_map';
		$sql          = "UPDATE `{$wpdb->postmeta}` pm
			 INNER JOIN `{$id_map_table}` im
			     ON CAST(pm.meta_value AS UNSIGNED) = im.source_id
			    AND im.site_job_id = %d
			    AND im.object_type = 'attachment'
			 SET pm.meta_value = im.dest_id
			 WHERE pm.meta_key = '_thumbnail_id'" . $extra_where;

		$wpdb->query( $wpdb->prepare( $sql, array_merge( [ $site_job_id ], $extra_params ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function maybe_send_notification( int $migration_id ): void {
		$migration = MigrationRegistry::get_migration( $migration_id );
		if ( ! $migration || ! $migration->notification_email ) {
			return;
		}
		wp_mail(
			$migration->notification_email,
			sprintf( '[HB Migrator] Migration from %s complete', $migration->source_url ),
			sprintf(
				"All sites from %s have been migrated successfully.\n\nMigration ID: %d",
				$migration->source_url,
				$migration_id
			)
		);
	}
}
