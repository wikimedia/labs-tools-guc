<?php

namespace Guc;

use Wikimedia\IPUtils;

class IPInfo {
	/**
	 * @param string $ip
	 * @return bool
	 */
	public static function valid( $ip ) {
		return IPUtils::isValid( $ip );
	}

	/**
	 * @param string $ip
	 * @return string|null Normalized form of IPv4 and IPv6 address,
	 * or null for invalid input.
	 */
	public static function normalize( $ip ) {
		if ( !self::valid( $ip ) ) {
			return null;
		}
		// While IPv4 addresses are nearly always both displayed and stored
		// in the same normal form, IPv6 addresses are generally displayed
		// in short/lowercase-form, but stored in normalized long/uppercase-form.
		// This means it's quite common for a user to e.g. copy and paste
		// such short forms and then expect to paste them in a tool like GUC.
		//
		// This also normalizes the rare valid-yet-non-normal forms of IPv4.
		//
		// Example:
		// * "2001:db8:85a3::8a2e:370:7334" > "2001:DB8:85A3:0:0:8A2E:370:7334"
		// * "080.072.250.04"               > "80.72.250.4"
		return IPUtils::sanitizeIP( $ip );
	}

	/**
	 * @param string $ip IP address, prefix, range, user name, or other actor name
	 * @return array|null IP info, or null for anything that is either not a
	 *  valid single IP address. Optional array keys:
	 *
	 *  - string|null 'host' Reverse DNS lookup
	 *  - int 'asn'
	 *  - string 'description' ASN description text
	 *  - string 'range' IP CIDR range
	 */
	public static function get( $ip ) {
		if ( !self::valid( $ip ) ) {
			return null;
		}
		// gethostbyaddr() usually doesn't give much for IPv6 addresses
		// Use ASN information to still provide some information that
		// may be useful to identify a group of related IP-adresses.
		$info = self::getAsnInfo( $ip ) ?? [];
		$info['host'] = self::getHost( $ip );
		return $info;
	}

	protected static function getHost( $ip4 ): ?string {
		$result = @gethostbyaddr( $ip4 );
		if ( !$result || $result == $ip4 ) {
			return null;
		}
		return $result;
	}

	protected static function getAsnInfo( $ip ): ?array {
		if ( IPUtils::isIPv6( $ip ) ) {
			// Service: https://www.team-cymru.org/IP-ASN-mapping.html#dns
			return self::getAsnFromCymru(
				self::arpaForIp6( $ip, '.origin6.asn.cymru.com' )
			);
		} else {
			// Service: https://www.team-cymru.org/IP-ASN-mapping.html#dns
			return self::getAsnFromCymru(
				self::arpaForIp4( $ip, '.origin.asn.cymru.com' )
			);
		}
	}

	/**
	 * Get reverse-DNS hostname for IPv4 address.
	 *
	 * See also:
	 * - <https://en.wikipedia.org/wiki/Reverse_DNS_lookup#IPv4_reverse_resolution>
	 * - RFC 3172 <https://tools.ietf.org/html/rfc3172>
	 */
	private static function arpaForIp4( string $ip4, string $suffix = '.in-addr.arpa' ): string {
		return implode( '.', array_reverse( explode( '.', $ip4 ) ) ) . $suffix;
	}

	/**
	 * Get reverse-DNS hostname for IPv6 address.
	 *
	 * See also:
	 * - <https://en.wikipedia.org/wiki/IPv6>
	 * - RFC 3596 <https://tools.ietf.org/html/rfc3596>
	 * - RFC 3172 <https://tools.ietf.org/html/rfc3172>
	 */
	private static function arpaForIp6( string $ip6, string $suffix = '' ): string {
		// Inspired by <http://stackoverflow.com/a/6621473/319266>
		$addr = inet_pton( $ip6 );
		$unpack = unpack( 'H*hex', $addr );
		$hex = $unpack['hex'];
		return implode( '.', array_reverse( str_split( $hex ) ) ) . $suffix;
	}

	private static function getDnsText( $hostname ) {
		// Disable warnings with @
		// Avoid log flood from https://bugs.php.net/bug.php?id=73149
		$tmp = @dns_get_record( $hostname, DNS_TXT );
		if ( !isset( $tmp[0]['type'] ) || $tmp[0]['type'] !== 'TXT' || !isset( $tmp[0]['txt'] ) ) {
			return false;
		}
		return $tmp[0]['txt'];
	}

	private static function getAsnDescription( $asn ): ?string {
		// Service: https://www.team-cymru.org/IP-ASN-mapping.html#dns
		$txt = self::getDnsText( 'AS' . intval( $asn ) . '.asn.cymru.com' );
		if ( !$txt ) {
			return null;
		}
		// Result format:
		// "14907 | US | arin | 2006-09-27 | WIKIMEDIA - Wikimedia Foundation Inc., US"
		$matches = null;
		preg_match( '/[^|]+$/', $txt, $matches );
		if ( !isset( $matches[0] ) ) {
			return null;
		}
		return trim( $matches[0] );
	}

	private static function getAsnFromCymru( $reverseOrigin ): ?array {
		// Service: https://www.team-cymru.org/IP-ASN-mapping.html#dns
		$txt = self::getDnsText( $reverseOrigin );
		if ( !$txt ) {
			return null;
		}
		// Result format:
		// "14907 | 2620:0:860::/46 | US | arin | 2007-10-02"
		$parts = preg_split( '/[\s|]+/', $txt );
		if ( !isset( $parts[0] ) || !ctype_digit( $parts[0] ) || !isset( $parts[1] ) ) {
			return null;
		}
		return [
			'asn' => (int)$parts[0],
			'description' => self::getAsnDescription( $parts[0] ) ?? '',
			'range' => $parts[1],
		];
	}
}
