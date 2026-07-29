<?php

namespace HBMigrator\Destination;

use HBMigrator\MigrationRegistry;

/**
 * U1: storage foundation for the migration audit report (see
 * docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md). Owns the `hbm_audit_report`
 * custom post type (one report post per site job, stored on the destination's primary/network
 * site) and every read/write operation the rest of the audit feature performs against it. No
 * new custom DB table — everything lives in this CPT's post + postmeta, per plan R7.
 *
 * Failure containment (critical, non-negotiable — see plan "Key Technical Decisions" and
 * SyncReceiver::deliver_sync_webhook_token()'s identical shape, which this mirrors exactly):
 * every public method below wraps its entire body in try/catch (\Throwable) and never rethrows.
 * These methods are called from inside the real migration pipeline's per-item loops (later
 * units), so an uncaught exception here would propagate into
 * PipelineController::handle_batch_failure()'s retry/failure machinery and could mark a
 * genuinely successful migration 'failed'. A caller never needs to know this class is fallible.
 */
class AuditReport {

	/**
	 * public => false: never publicly queryable/visible on the front end (this is destination-
	 * side operational data, not site content). show_ui => true is an explicit override — WordPress
	 * defaults show_ui to the value of `public`, so without this override the post type would have
	 * NO edit-post.php screen at all, breaking plan R9 ("inspected via the existing post-editing
	 * screen"). show_in_menu => false keeps it reachable only by direct URL, not as a visible
	 * admin-menu entry. rewrite => false and show_in_rest => false close off the two other surfaces
	 * a post type can leak through (pretty permalinks, the REST API) that public => false alone
	 * does not fully guarantee once show_ui is forced back on.
	 */
	public const POST_TYPE = 'hbm_audit_report';

	/** postmeta key (unique) recording which site_job_id a report post belongs to. */
	private const META_SITE_JOB_ID = '_hbm_audit_site_job_id';

	/** postmeta key (unique) recording which migration_id a report post belongs to. */
	private const META_MIGRATION_ID = '_hbm_audit_migration_id';

	/**
	 * postmeta key (NOT unique — add_post_meta(..., false), many rows per key is intentional)
	 * for the outbound-request trail. record()'s $entry decides request vs. write by its own
	 * 'type' field (see append_entry()) since neither record() nor record_for_migration() take a
	 * separate $type parameter per the plan's method signatures.
	 */
	private const META_REQUEST = '_hbm_audit_request';

	/** postmeta key (NOT unique) for the destination write-action trail. See META_REQUEST. */
	private const META_WRITE = '_hbm_audit_write';

	/**
	 * Site-wide (network) option name prefix used to stage record_for_migration() entries
	 * before any site job's report exists yet. A dynamic per-migration option (rather than one
	 * shared option for every migration) keeps each migration's staged entries independently
	 * sized and trivially garbage-collectable later without scanning unrelated migrations.
	 */
	private const STAGING_OPTION_PREFIX = 'hbm_audit_staged_';

	/**
	 * U7: maximum number of posts rendered inline in the "Full Detail" section of
	 * render_summary()'s output. For a very large migration, rendering every post's full
	 * comparison detail into post_content would make the wp-admin post editor unresponsive (see
	 * plan Risks, "post_content size for very large migrations") — this caps the inline render
	 * only; the complete per-post detail is never capped in storage (AuditComparator's own
	 * `_hbm_audit_compare_result` postmeta rows), always readable in full via
	 * `wp post meta list <report_post_id>` regardless of this cap. Filterable (see
	 * full_detail_cap()) for the same testability reason AuditComparator's TIME_LIMIT/BATCH_SIZE
	 * are filterable — a test can force a tiny cap to deterministically exercise truncation
	 * without needing hundreds of real posts.
	 */
	private const FULL_DETAIL_CAP = 300;

	/**
	 * Registers the hbm_audit_report post type. Called on WordPress's `init` hook — wired from
	 * Plugin::setup(). Runs on every request (this plugin registers everything unconditionally,
	 * matching Action Scheduler's own bundled CPT registration precedent in this codebase).
	 */
	public static function register_post_type(): void {
		try {
			// Security requirement (non-optional — see plan U1 approach): every relevant post-type
			// capability is remapped to the same manage_network capability Admin\AdminPage already
			// gates its network-admin screen on (includes/admin/class-admin-page.php,
			// add_submenu_page()'s 'manage_network' argument), NOT the default 'post' capabilities.
			// Without this, any user who can edit_posts on the primary site (not just migration
			// operators) could open a report and read another site job's content/authorship data,
			// which may include source-side PII such as author emails.
			//
			// map_meta_cap => false is what makes this work: WordPress core's map_meta_cap()
			// (wp-includes/capabilities.php) has a specific carve-out for meta caps like
			// 'edit_post'/'read_post'/'delete_post' — when a post type's map_meta_cap property is
			// false, map_meta_cap() uses the post type's literal cap->edit_post (etc.) string as
			// the single required capability, instead of translating it into ownership-aware
			// primitive caps (edit_posts / edit_others_posts / edit_published_posts / ...). Setting
			// every one of those primitive AND meta caps to 'manage_network' below means every
			// capability check this post type is subject to — current_user_can('edit_post', $id),
			// current_user_can('read_post', $id), the edit.php list table's current_user_can(
			// $post_type->cap->edit_posts ), etc. — all resolve to "does this user have
			// manage_network", full stop.
			$capabilities = [
				'edit_post'              => 'manage_network',
				'read_post'               => 'manage_network',
				'delete_post'             => 'manage_network',
				'edit_posts'              => 'manage_network',
				'edit_others_posts'       => 'manage_network',
				'delete_posts'            => 'manage_network',
				'publish_posts'           => 'manage_network',
				'read_private_posts'      => 'manage_network',
				'delete_private_posts'    => 'manage_network',
				'delete_published_posts'  => 'manage_network',
				'delete_others_posts'     => 'manage_network',
				'edit_private_posts'      => 'manage_network',
				'edit_published_posts'    => 'manage_network',
				'create_posts'            => 'manage_network',
			];

			register_post_type( self::POST_TYPE, [
				'label'               => __( 'Migration Audit Reports', 'hb-migrator' ),
				'labels'              => [
					'name'          => __( 'Migration Audit Reports', 'hb-migrator' ),
					'singular_name' => __( 'Migration Audit Report', 'hb-migrator' ),
				],
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_admin_bar'   => false,
				'show_in_nav_menus'   => false,
				'rewrite'             => false,
				'query_var'           => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'can_export'          => false,
				// Distinct from the default 'post' capability_type per plan U1 — capabilities
				// below are set explicitly for every relevant cap regardless, but a distinct
				// capability_type avoids this post type quietly inheriting a default-role
				// capability we didn't intend to grant, on any WP core code path this class's
				// explicit 'capabilities' array doesn't cover.
				'capability_type'     => [ 'hbm_audit_report', 'hbm_audit_reports' ],
				'map_meta_cap'        => false,
				'capabilities'        => $capabilities,
				'supports'            => [ 'title', 'editor', 'custom-fields' ],
			] );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HB Migrator: failed to register hbm_audit_report post type: ' . $e->getMessage() );
		}
	}

	/**
	 * Returns the report post ID for a site job, creating it — on the primary site, via
	 * switch_to_blog( get_main_site_id() ) — only on first use (lazy creation, per plan: NOT
	 * gated on dest_blog_id existing, since a site job that fails before creating its subsite
	 * should still get a report). On creation, copies in any entries already staged via
	 * record_for_migration() for this site job's migration, before returning the new post ID.
	 *
	 * Returns 0 if the report post could not be found or created due to an internal failure —
	 * never throws (see class docblock). Callers that only care about "does a report exist"
	 * should treat 0 the same as "not available right now."
	 */
	public static function get_or_create_for_site_job( int $site_job_id ): int {
		try {
			switch_to_blog( get_main_site_id() );
			try {
				$existing = self::find_report_post_id( $site_job_id );
				if ( null !== $existing ) {
					return $existing;
				}

				$post_id = wp_insert_post( [
					'post_type'    => self::POST_TYPE,
					'post_status'  => 'private',
					'post_title'   => sprintf( 'Audit report — site job #%d', $site_job_id ),
					'post_content' => '',
				], true );

				if ( is_wp_error( $post_id ) || ! $post_id ) {
					throw new \RuntimeException(
						'wp_insert_post() failed: ' . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : 'unknown error' )
					);
				}

				$post_id = (int) $post_id;
				add_post_meta( $post_id, self::META_SITE_JOB_ID, $site_job_id, true );

				$job = MigrationRegistry::get_site_job( $site_job_id );
				if ( $job && ! empty( $job->migration_id ) ) {
					add_post_meta( $post_id, self::META_MIGRATION_ID, (int) $job->migration_id, true );
					self::copy_staged_migration_entries( $post_id, (int) $job->migration_id );
				}

				return $post_id;
			} finally {
				restore_current_blog();
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HB Migrator: AuditReport::get_or_create_for_site_job() failed for site job ' . $site_job_id . ': ' . $e->getMessage() );
			return 0;
		}
	}

	/**
	 * Appends one trail entry for a site job — request trail or write-action trail, decided by
	 * $entry['type'] (expected 'request' or 'write'; anything else/absent is treated as 'write')
	 * since this method's signature (matching the plan exactly) takes no separate $type
	 * parameter. Triggers lazy creation via get_or_create_for_site_job() if no report exists yet
	 * for this site job. Stores $scope ('migration' or 'site_job') as part of the entry data so
	 * a reader can tell which. Uses add_post_meta(..., false) — unique=false, many rows per key
	 * is intentional.
	 *
	 * Calls clean_post_cache() after appending: a caller mid-loop may be running under
	 * wp_suspend_cache_invalidation( true ) (a process-global flag some importers set for their
	 * own writes — see PostImporter::import_batch()), which would otherwise leave this report
	 * post's cache stale.
	 */
	public static function record( int $site_job_id, string $scope, array $entry ): void {
		try {
			$post_id = self::get_or_create_for_site_job( $site_job_id );
			if ( ! $post_id ) {
				return;
			}

			switch_to_blog( get_main_site_id() );
			try {
				self::append_entry( $post_id, $entry, $scope );
			} finally {
				restore_current_blog();
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HB Migrator: AuditReport::record() failed for site job ' . $site_job_id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Stages an entry keyed by migration_id, for the narrow case where the call happens BEFORE
	 * any site job's report can exist yet (MigrationReceiver::begin()'s initial source/sites
	 * listing, and UserImporter's network-user actions — both run once per migration, before any
	 * site-job-specific stage). Stored in a network-wide option (readable regardless of which
	 * blog is currently active, and readable later without an existing site job) rather than
	 * postmeta on a placeholder post, since there's no natural post to attach these to yet.
	 *
	 * get_or_create_for_site_job() copies these in the first time each sibling site job's report
	 * is created for that migration (see copy_staged_migration_entries()) — not on every access,
	 * since multiple site jobs under one migration each need their own copy, tagged scope:
	 * migration.
	 */
	public static function record_for_migration( int $migration_id, string $scope, array $entry ): void {
		try {
			$option_key = self::staging_option_key( $migration_id );
			$staged     = get_site_option( $option_key, [] );
			if ( ! is_array( $staged ) ) {
				$staged = [];
			}

			$staged[] = [
				'scope' => $scope,
				'entry' => $entry,
			];

			update_site_option( $option_key, $staged );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HB Migrator: AuditReport::record_for_migration() failed for migration ' . $migration_id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * R7 (docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, "U5. UserImporter
	 * batched staged writes"): batched sibling of record_for_migration() — appends a whole batch
	 * of entries to the same per-migration staging option in one get_site_option()/
	 * update_site_option() round-trip, instead of one round-trip per entry.
	 * UserImporter::process()'s per-user loop is the only caller today: at real production scale
	 * that loop's previous one-call-per-user use of record_for_migration() made every user's
	 * write re-fetch and re-store the entire (ever-growing) staged-entries array — an O(n^2)
	 * pattern that only bites at large migrations. Batching several users' entries into one call
	 * removes that quadratic growth.
	 *
	 * Stores each entry in the exact same shape record_for_migration() already uses
	 * (['scope' => ..., 'entry' => ...] per staged item, one $scope shared by the whole batch,
	 * matching record_for_migration()'s own single-scope-per-call signature) — so
	 * copy_staged_migration_entries() reads this option without needing to know or care whether
	 * a given item arrived via record_for_migration() or this batched method.
	 *
	 * $entries is a plain list of entry arrays (the same shape each one would have been passed as
	 * to record_for_migration() individually) — not pre-wrapped with 'scope'/'entry' keys; this
	 * method does that wrapping itself, once per entry, using the single $scope for the whole
	 * batch.
	 *
	 * Never throws (see class docblock) — a failure here is swallowed and logged, exactly like
	 * every other method in this class.
	 */
	public static function record_batch_for_migration( int $migration_id, string $scope, array $entries ): void {
		try {
			if ( empty( $entries ) ) {
				return;
			}

			$option_key = self::staging_option_key( $migration_id );
			$staged     = get_site_option( $option_key, [] );
			if ( ! is_array( $staged ) ) {
				$staged = [];
			}

			foreach ( $entries as $entry ) {
				$staged[] = [
					'scope' => $scope,
					'entry' => $entry,
				];
			}

			update_site_option( $option_key, $staged );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HB Migrator: AuditReport::record_batch_for_migration() failed for migration ' . $migration_id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Deletes the report post for a site job. Safe no-op if no report exists. The cleanup
	 * helper U8 (a separate, already-blocked unit) wires into the three existing sync-finalize
	 * call sites — this class does not call itself from anywhere; callers own when deletion
	 * happens.
	 */
	public static function delete_for_site_job( int $site_job_id ): void {
		try {
			switch_to_blog( get_main_site_id() );
			try {
				$post_id = self::find_report_post_id( $site_job_id );
				if ( $post_id ) {
					wp_delete_post( $post_id, true );
				}
			} finally {
				restore_current_blog();
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HB Migrator: AuditReport::delete_for_site_job() failed for site job ' . $site_job_id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * R1 (docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, "U1. CLI report-post-id
	 * lookup"): a public, non-creating way to look up a site job's report post ID, for
	 * `wp hbm migration list` (Cli\MigrationCommand::list()) and any other caller that only
	 * wants to know "does a report exist, and if so what's its post ID" without fabricating one
	 * as a side effect. Deliberately does NOT call get_or_create_for_site_job() — that method's
	 * wp_insert_post() call is exactly the side effect this method must avoid, since merely
	 * listing migrations must never create an empty report post as a byproduct.
	 *
	 * Switches to the primary site itself — find_report_post_id() documents that it does not —
	 * so callers don't need to know this class's storage lives on the primary/network site.
	 * Returns null both when no report exists and on any internal failure (see class docblock);
	 * a caller has no way to distinguish the two, matching every other AuditReport method's
	 * failure-containment discipline.
	 */
	public static function get_report_post_id_for_site_job( int $site_job_id ): ?int {
		try {
			switch_to_blog( get_main_site_id() );
			try {
				return self::find_report_post_id( $site_job_id );
			} finally {
				restore_current_blog();
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HB Migrator: AuditReport::get_report_post_id_for_site_job() failed for site job ' . $site_job_id . ': ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * U6 read-access addition (see docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md,
	 * "U6. Comparator: hashing, normalization, and count comparison"). AuditReport previously had
	 * no way to read write-action trail entries back out — AuditComparator needs the full set
	 * for a site job to build its own per-source_id "latest entry wins" lookup (a resumed/
	 * retried batch can produce more than one _hbm_audit_write entry for the same source_id).
	 * Returns entries in ascending meta_id (insertion) order — get_post_meta( ..., false )
	 * already returns rows in that order, so no additional ordering step is needed here.
	 *
	 * Deliberately does NOT create a report via get_or_create_for_site_job() if none exists: a
	 * comparison pass only ever runs after write-trail entries have already been recorded (see
	 * SearchReplace::finalize()), so a missing report here means there is genuinely nothing to
	 * compare, not something worth lazily creating an empty report for.
	 *
	 * Never throws (see class docblock) — returns an empty array on internal failure.
	 */
	public static function get_write_entries_for_site_job( int $site_job_id ): array {
		try {
			switch_to_blog( get_main_site_id() );
			try {
				$post_id = self::find_report_post_id( $site_job_id );
				if ( ! $post_id ) {
					return [];
				}
				return get_post_meta( $post_id, self::META_WRITE, false ) ?: [];
			} finally {
				restore_current_blog();
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HB Migrator: AuditReport::get_write_entries_for_site_job() failed for site job ' . $site_job_id . ': ' . $e->getMessage() );
			return [];
		}
	}

	/**
	 * U7: renders AuditComparator's computed comparison results (compute_counts()'s aggregate
	 * counts and get_post_comparison_results()'s per-post detail) into the report post's
	 * post_content, in the order R6 requires: high-level counts first, then every flagged
	 * (diverged) post, then full per-post detail. Called exactly once, by
	 * AuditComparator::process(), after the LAST self-chained `hbm_audit_compare` batch completes
	 * — never after an intermediate batch (see that method's own docblock).
	 *
	 * Gets (creating if necessary — unlikely this is the first write for a site job at this point
	 * in the pipeline, but keeps this method's contract consistent with every other AuditReport
	 * method) the report post ID, on the primary site, matching this class's existing
	 * switch_to_blog( get_main_site_id() )/restore_current_blog() try/finally bracketing.
	 *
	 * Sanitization: any rendered text that ultimately originates from source content (post
	 * titles/slugs, both "expected" — the cached source snapshot — and "actual" — the current
	 * destination row) is passed through esc_html() before being embedded, mirroring
	 * CommentImporter's existing sanitization discipline for source-derived free text
	 * (class-comment-importer.php) applied to this class's own rendering context — source content
	 * can originate from anonymous/untrusted contributors, and this is written into post_content
	 * as HTML. Structural/numeric content (counts, boolean match flags, fixed field names like
	 * "slug"/"title") needs no escaping.
	 *
	 * Writes via wp_update_post(..., true) and checks is_wp_error() — log-and-swallow on failure,
	 * never throw, per this class's universal failure-containment discipline. Calls
	 * clean_post_cache() afterward regardless, matching this class's other methods' caching
	 * discipline. Wraps its entire body in try/catch (\Throwable).
	 *
	 * @param int   $site_job_id
	 * @param array $counts       AuditComparator::compute_counts()'s return shape (possibly an
	 *                            empty array on internal failure — see that method's contract).
	 * @param array $post_results AuditComparator::get_post_comparison_results()'s return shape
	 *                            (source_id => result), possibly empty.
	 */
	public static function render_summary( int $site_job_id, array $counts, array $post_results ): void {
		try {
			$post_id = self::get_or_create_for_site_job( $site_job_id );
			if ( ! $post_id ) {
				return;
			}

			$rendered = self::render_counts_section( $counts )
				. "\n\n" . self::render_flagged_section( $post_results )
				. "\n\n" . self::render_full_detail_section( $post_results, $post_id );

			switch_to_blog( get_main_site_id() );
			try {
				// wp_update_post() only wp_slash()'s for you when $postarr is an object, not a
				// plain array (see wp-includes/post.php) — mirrors the same convention already
				// established in PostImporter::import_batch(). Without this, wp_unslash() further
				// downstream would silently strip any backslash in $rendered (e.g. from a
				// source-derived title/slug containing one).
				$result = wp_update_post( wp_slash( [
					'ID'           => $post_id,
					'post_content' => $rendered,
				] ), true );

				if ( is_wp_error( $result ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'HB Migrator: AuditReport::render_summary() failed to update post ' . $post_id . ' for site job ' . $site_job_id . ': ' . $result->get_error_message() );
				}

				clean_post_cache( $post_id );
			} finally {
				restore_current_blog();
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HB Migrator: AuditReport::render_summary() failed for site job ' . $site_job_id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * R6 section 1: high-level counts — attempted/landed/failed/destination_actual for
	 * attachment and term, plus term's by_taxonomy breakdown. $counts may be an empty array
	 * (AuditComparator::compute_counts()'s documented failure/missing-job return) — rendered as
	 * a clear "unavailable" line rather than a blank section.
	 */
	private static function render_counts_section( array $counts ): string {
		$lines = [ '=== Counts ===' ];

		if ( empty( $counts ) ) {
			$lines[] = '(count comparison unavailable)';
			return implode( "\n", $lines );
		}

		foreach ( [ 'attachment' => 'Attachment', 'term' => 'Term' ] as $key => $label ) {
			if ( ! isset( $counts[ $key ] ) || ! is_array( $counts[ $key ] ) ) {
				continue;
			}
			$c       = $counts[ $key ];
			$lines[] = sprintf(
				'%s: attempted=%d, landed=%d, failed=%d, destination_actual=%d',
				$label,
				(int) ( $c['attempted'] ?? 0 ),
				(int) ( $c['landed'] ?? 0 ),
				(int) ( $c['failed'] ?? 0 ),
				(int) ( $c['destination_actual'] ?? 0 )
			);

			if ( 'term' === $key && ! empty( $c['by_taxonomy'] ) && is_array( $c['by_taxonomy'] ) ) {
				$lines[] = '  By taxonomy:';
				foreach ( $c['by_taxonomy'] as $taxonomy => $count ) {
					// Taxonomy slugs are WP/plugin-registered identifiers, not raw source
					// content — esc_html() applied anyway for defense-in-depth, since a
					// third-party plugin could in principle register one from untrusted input.
					$lines[] = '    ' . esc_html( (string) $taxonomy ) . ': ' . (int) $count;
				}
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * R6 section 2: every post flagged as diverged — comparable-and-diverged (with its
	 * diverged_fields list) and not-comparable-but-diverged (e.g. destination_post_missing, with
	 * its reason) alike. Zero flagged posts renders a clear "No divergence found." line rather
	 * than an empty/missing section (required test scenario — see plan U7).
	 */
	private static function render_flagged_section( array $post_results ): string {
		$lines   = [ '=== Flagged Posts ===' ];
		$flagged = array_filter( $post_results, static fn( $r ) => ! empty( $r['diverged'] ) );

		if ( empty( $flagged ) ) {
			$lines[] = 'No divergence found.';
			return implode( "\n", $lines );
		}

		foreach ( $flagged as $result ) {
			$lines[] = self::render_flagged_line( (array) $result );
		}

		return implode( "\n", $lines );
	}

	private static function render_flagged_line( array $result ): string {
		$source_id = (int) ( $result['source_id'] ?? 0 );
		$dest_id   = (int) ( $result['dest_id'] ?? 0 );

		if ( empty( $result['comparable'] ) ) {
			$reason = esc_html( (string) ( $result['reason'] ?? 'unknown' ) );
			return "- source_id={$source_id} dest_id={$dest_id}: {$reason}";
		}

		$fields = implode( ', ', array_map( 'esc_html', array_map( 'strval', (array) ( $result['diverged_fields'] ?? [] ) ) ) );
		return "- source_id={$source_id} dest_id={$dest_id}: diverged_fields=[{$fields}]";
	}

	/**
	 * R6 section 3: full per-post detail — every entry in $post_results, comparable and not —
	 * capped at full_detail_cap() entries for very large migrations, with a trailing note when
	 * truncated pointing at `wp post meta list <report_post_id>` as the complete, uncapped source
	 * (the underlying _hbm_audit_compare_result postmeta rows are never capped — only this inline
	 * render is).
	 */
	private static function render_full_detail_section( array $post_results, int $post_id ): string {
		$lines = [ '=== Full Detail ===' ];
		$cap   = self::full_detail_cap();
		$total = count( $post_results );

		if ( 0 === $total ) {
			$lines[] = '(no posts compared)';
			return implode( "\n", $lines );
		}

		$shown = 0;
		foreach ( $post_results as $result ) {
			if ( $shown >= $cap ) {
				break;
			}
			$lines[] = self::render_full_detail_line( (array) $result );
			++$shown;
		}

		if ( $total > $cap ) {
			$lines[] = sprintf(
				'(showing %d of %d posts — the complete set remains available via `wp post meta list %d` regardless of this cap)',
				$shown,
				$total,
				$post_id
			);
		}

		return implode( "\n", $lines );
	}

	private static function render_full_detail_line( array $result ): string {
		$source_id = (int) ( $result['source_id'] ?? 0 );
		$dest_id   = (int) ( $result['dest_id'] ?? 0 );

		if ( empty( $result['comparable'] ) ) {
			$reason = esc_html( (string) ( $result['reason'] ?? 'unknown' ) );
			return "- source_id={$source_id} dest_id={$dest_id} comparable=no reason={$reason}";
		}

		$diverged       = ! empty( $result['diverged'] ) ? 'yes' : 'no';
		$expected_title = esc_html( (string) ( $result['expected_title'] ?? '' ) );
		$actual_title   = esc_html( (string) ( $result['actual_title'] ?? '' ) );
		$expected_slug  = esc_html( (string) ( $result['expected_slug'] ?? '' ) );
		$actual_slug    = esc_html( (string) ( $result['actual_slug'] ?? '' ) );

		return sprintf(
			"- source_id=%d dest_id=%d comparable=yes diverged=%s\n" .
			"    slug: expected=\"%s\" actual=\"%s\" match=%s\n" .
			"    title: expected=\"%s\" actual=\"%s\" match=%s\n" .
			'    content_hash_match=%s postmeta_hash_match=%s authorship_match=%s (expected_author_id=%d, actual_author_id=%d)',
			$source_id,
			$dest_id,
			$diverged,
			$expected_slug,
			$actual_slug,
			! empty( $result['slug_match'] ) ? 'yes' : 'no',
			$expected_title,
			$actual_title,
			! empty( $result['title_match'] ) ? 'yes' : 'no',
			! empty( $result['content_hash_match'] ) ? 'yes' : 'no',
			! empty( $result['postmeta_hash_match'] ) ? 'yes' : 'no',
			! empty( $result['authorship_match'] ) ? 'yes' : 'no',
			(int) ( $result['expected_author_id'] ?? 0 ),
			(int) ( $result['actual_author_id'] ?? 0 )
		);
	}

	/**
	 * FULL_DETAIL_CAP, filtered — same testability rationale as AuditComparator's time_limit()/
	 * batch_size() filters: a test can force a tiny cap to deterministically exercise the
	 * "truncated and annotated" full-detail path without needing hundreds of real posts.
	 * Production code never needs this filter.
	 */
	private static function full_detail_cap(): int {
		return max( 1, (int) apply_filters( 'hbm_audit_report_detail_cap', self::FULL_DETAIL_CAP ) );
	}

	/**
	 * Looks up an existing report post ID for a given site_job_id via a direct postmeta query
	 * (mirrors IdMap's own single-prepare-per-call convention, includes/class-id-map.php) rather
	 * than get_posts()/WP_Query, since we only need a single scalar post ID keyed by an exact
	 * meta_value match. Must be called while already switched to the primary site — this method
	 * does not switch blogs itself.
	 */
	private static function find_report_post_id( int $site_job_id ): ?int {
		global $wpdb;
		$post_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d LIMIT 1",
			self::META_SITE_JOB_ID,
			$site_job_id
		) );
		return null !== $post_id ? (int) $post_id : null;
	}

	/**
	 * Shared by record() and copy_staged_migration_entries() so both append trail entries via
	 * the exact same meta-key-selection logic — record() for a fresh entry,
	 * copy_staged_migration_entries() for entries staged earlier via record_for_migration().
	 * Must be called while already switched to the primary site.
	 */
	private static function append_entry( int $post_id, array $entry, string $scope ): void {
		$meta_key = ( isset( $entry['type'] ) && 'request' === $entry['type'] ) ? self::META_REQUEST : self::META_WRITE;
		$data     = array_merge( $entry, [ 'scope' => $scope ] );

		self::write_meta_row( $post_id, $meta_key, $data );
	}

	/**
	 * R3 (docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, "U2. Shared write-trail
	 * contract: entry normalization and author resolution"): the shared postmeta-write mechanics
	 * append_entry() already performed inline, extracted so AuditComparator::store_result() can
	 * reuse the exact same sequence for its own `_hbm_audit_compare_result` rows instead of
	 * duplicating wp_slash()/add_post_meta()/clean_post_cache() itself.
	 *
	 * add_metadata() (wp-includes/meta.php, called by add_post_meta()) unconditionally
	 * wp_unslash()'s $meta_value before storing — even for a value that was never slashed in the
	 * first place — silently stripping any backslash a cached source field (post content, a
	 * Windows-style file path, etc.) might legitimately contain. wp_slash() here cancels that
	 * internal unslash out, the same convention wp_insert_post()/wp_update_post() require for
	 * plain-array input (see PostImporter::import_batch()/AuditReport's own render_summary()).
	 * clean_post_cache() afterward matches this class's other methods' caching discipline — a
	 * caller mid-loop (e.g. PostImporter::import_batch()) may be running under
	 * wp_suspend_cache_invalidation( true ), which would otherwise leave this post's cache stale.
	 *
	 * Must be called while already switched to the primary site — this method does not switch
	 * blogs itself (mirrors find_report_post_id()'s identical convention). Uses
	 * add_post_meta(..., false) — unique=false, many rows per key is intentional, matching every
	 * existing caller's own storage convention.
	 */
	public static function write_meta_row( int $post_id, string $meta_key, array $data ): void {
		add_post_meta( $post_id, $meta_key, wp_slash( $data ), false );
		clean_post_cache( $post_id );
	}

	/**
	 * Copies every entry staged via record_for_migration() for $migration_id into the freshly
	 * created report post $post_id. Called exactly once, from get_or_create_for_site_job(),
	 * right after a report post is first created for a site job under this migration — realizing
	 * the "migration-level entries copied into every sibling site job's report" Key Technical
	 * Decision. Must be called while already switched to the primary site.
	 */
	private static function copy_staged_migration_entries( int $post_id, int $migration_id ): void {
		$staged = get_site_option( self::staging_option_key( $migration_id ), [] );
		if ( empty( $staged ) || ! is_array( $staged ) ) {
			return;
		}

		foreach ( $staged as $item ) {
			self::append_entry( $post_id, (array) ( $item['entry'] ?? [] ), (string) ( $item['scope'] ?? 'migration' ) );
		}
	}

	private static function staging_option_key( int $migration_id ): string {
		return self::STAGING_OPTION_PREFIX . $migration_id;
	}
}
