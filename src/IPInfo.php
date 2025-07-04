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
	 * @return array{host:?string,as:?array}|null IP info, or null for anything that is
	 *  not a valid single IP address.
	 *
	 *  - string|null 'host' Reverse DNS lookup
	 *  - array|null 'as' AS information
	 *    - int 'asn'
	 *    - string 'description' ASN description text
	 *    - string 'range' IP CIDR range
	 */
	public static function getIpInfo( string $ip ): ?array {
		if ( !self::valid( $ip ) ) {
			return null;
		}
		// gethostbyaddr() is useful for IPv4, because internet service providers generally
		// provide reverse DNS for IPv4 addresses, in a way that prominently features the
		// provider's name in the domain, and often mentions an approximate geography too.
		//
		// For IPv6, this rare and almost always fails or returns generic information.
		//
		// NOTE: gethostbyaddr() returns the input string as-is on failure, as such,
		// discard that and treat it the same as false.
		$host = @gethostbyaddr( $ip );
		return [
			'host' => ( !$host || $host === $ip ) ? null : $host,
			// We add AS information to describe the larger group that this
			// IP address belongs to (ISP, telco, webhost, geography), even for IPv6.
			'as' => self::getAsInfo( $ip ),
		];
	}

	protected static function getAsInfo( $ip ): ?array {
		// Service: https://www.team-cymru.org/IP-ASN-mapping.html#dns
		// Result format: "14907 | 2620:0:860::/46 | US | arin | 2007-10-02"
		$reverseOrigin = IPUtils::isIPv6( $ip )
			? self::arpaForIp6( $ip, '.origin6.asn.cymru.com' )
			: self::arpaForIp4( $ip, '.origin.asn.cymru.com' );
		$txt = self::getDnsText( $reverseOrigin );
		if ( !$txt ) {
			return null;
		}
		$parts = preg_split( '/\s*\|\s*/', $txt, 5 );
		if ( !isset( $parts[0] ) || !ctype_digit( $parts[0] ) || !isset( $parts[1] ) ) {
			return null;
		}
		$asn = (int)$parts[0];
		$range = $parts[1];

		// Service: https://www.team-cymru.org/IP-ASN-mapping.html#dns
		// Result format: "14907 | US | arin | 2006-09-27 | WIKIMEDIA - Wikimedia Foundation, US"
		$txt = self::getDnsText( 'AS' . $asn . '.asn.cymru.com' );
		// Consider the desc optional. If this fails, we still return the AS number and range.
		$parts = preg_split( '/\s*\|\s*/', $txt ?? '', 5 );
		$desc = $parts[4] ?? '';
		return [
			'asn' => $asn,
			'description' => $desc,
			'range' => $range,
		];
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

	private static function getDnsText( $hostname ): ?string {
		// Disable warnings with @
		// Avoid log flood from https://bugs.php.net/bug.php?id=73149
		$tmp = @dns_get_record( $hostname, DNS_TXT );
		return $tmp[0]['txt'] ?? null;
	}
}
