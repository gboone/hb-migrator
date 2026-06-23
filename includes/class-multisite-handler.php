<?php

namespace HBMigrator;

class MultisiteHandler {

	/**
	 * Compute the destination subsite path from a source site's siteurl.
	 *
	 * When $network_domain is supplied, subdomain source sites that share the
	 * same base domain as the network are mapped to a clean slug instead of a
	 * dotted path, which causes redirect loops on subdirectory multisite
	 * installations (Nginx treats dotted path segments as file extensions).
	 *
	 * https://example.com                       → /example.com/
	 * https://news.example.com                  → /news.example.com/
	 * https://example.com/store                 → /example.com/store/
	 * https://greg.harmsboone.org  (net: sites.harmsboone.org) → /greg/
	 * https://news.greg.harmsboone.org (net: sites.harmsboone.org) → /news.greg.harmsboone.org/ (dots remain — fall back)
	 */
	public static function dest_path_for_siteurl( string $siteurl, string $network_domain = '' ): string {
		$parsed = wp_parse_url( $siteurl );
		$host   = $parsed['host'] ?? '';
		$path   = isset( $parsed['path'] ) ? rtrim( $parsed['path'], '/' ) : '';

		if ( $network_domain && $host ) {
			$base   = self::base_domain( $network_domain );
			$suffix = '.' . $base;
			// Only strip when the source host is exactly one label + the base domain.
			// news.greg.example.com still has a dot in the remaining label — keep full path.
			if ( $base && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				$slug = substr( $host, 0, -strlen( $suffix ) );
				if ( $slug && false === strpos( $slug, '.' ) ) {
					return '/' . $slug . $path . '/';
				}
			}
		}

		return '/' . $host . $path . '/';
	}

	/**
	 * Strip the leftmost label from a domain to get the registrable base.
	 * sites.harmsboone.org → harmsboone.org
	 * harmsboone.org       → harmsboone.org  (already a base domain)
	 */
	private static function base_domain( string $domain ): string {
		$parts = explode( '.', $domain );
		if ( count( $parts ) > 2 ) {
			array_shift( $parts );
		}
		return implode( '.', $parts );
	}
}
