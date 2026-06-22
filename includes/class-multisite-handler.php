<?php

namespace HBMigrator;

class MultisiteHandler {

	/**
	 * Compute the destination subsite path from a source site's siteurl.
	 *
	 * https://example.com        → /example.com/
	 * https://news.example.com   → /news.example.com/
	 * https://example.com/store  → /example.com/store/
	 */
	public static function dest_path_for_siteurl( string $siteurl ): string {
		$parsed = wp_parse_url( $siteurl );
		$host   = $parsed['host'] ?? '';
		$path   = isset( $parsed['path'] ) ? rtrim( $parsed['path'], '/' ) : '';
		return '/' . $host . $path . '/';
	}
}
