<?php
/**
 * Action Scheduler entry point.
 *
 * Replace this stub with the real Action Scheduler 3.9.x library via:
 *
 *   git subtree add --prefix lib/action-scheduler \
 *     https://github.com/woocommerce/action-scheduler.git \
 *     3.9.x --squash
 *
 * This stub exists so the plugin's require_once resolves during development
 * before the subtree is added. It provides no-op implementations of the AS
 * functions used by hb-migrator so the rest of the plugin code is syntactically
 * valid and IDE-friendly. Tests that exercise queue dispatch should mock these
 * functions or run against the real AS library.
 *
 * @see https://actionscheduler.org/usage/#load-order
 */

if ( defined( 'ACTION_SCHEDULER_VERSION' ) ) {
	return;
}

define( 'ACTION_SCHEDULER_VERSION', '3.9.0-stub' );

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	function as_enqueue_async_action( string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10 ): int {
		return 0;
	}
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10 ): int {
		return 0;
	}
}

if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	function as_has_scheduled_action( string $hook, ?array $args = null, string $group = '' ): bool {
		return false;
	}
}

if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	function as_unschedule_all_actions( string $hook = '', array $args = [], string $group = '' ): void {
		// no-op stub
	}
}

if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
	function as_get_scheduled_actions( array $args = [], string $return_format = OBJECT ): array {
		return [];
	}
}
